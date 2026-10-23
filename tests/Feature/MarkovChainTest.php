<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Itinerary;
use App\Models\User;
use App\Services\Recommendation\MarkovChainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkovChainTest extends TestCase
{
    use RefreshDatabase;

    private MarkovChainService $markov;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markov = new MarkovChainService();
    }

    private function itinerary(?User $user, array $categories, int $daysAgo = 1): void
    {
        $itinerary = new Itinerary(['city_code' => '7700000000000']);
        $itinerary->user_id = $user?->id;
        $itinerary->created_at = now()->subDays($daysAgo);
        $itinerary->save();

        foreach ($categories as $i => $category) {
            $itinerary->stops()->create([
                'event_id' => Event::factory()->category($category)->create()->id,
                'position' => $i + 1,
            ]);
        }
    }

    public function test_counts_weighted_transitions_and_starts(): void
    {
        $counts = $this->markov->countTransitions([
            [['state' => 'exhibition', 'weight' => 1.0], ['state' => 'cafe', 'weight' => 1.0], ['state' => 'concert', 'weight' => 2.0]],
            [['state' => 'exhibition', 'weight' => 1.0], ['state' => 'cafe', 'weight' => 0.5]],
        ]);

        $this->assertEqualsWithDelta(1.5, $counts['transitions']['exhibition']['cafe'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $counts['transitions']['cafe']['concert'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $counts['starts']['exhibition'], 1e-9);
    }

    public function test_rows_are_stochastic_with_laplace_smoothing(): void
    {
        $states = $this->markov->states();
        $matrix = $this->markov->normalize(['exhibition' => ['cafe' => 3.0]], $states, 0.5);

        foreach ($matrix as $row) {
            $this->assertEqualsWithDelta(1.0, array_sum($row), 1e-9);
        }

        // Ненаблюдавшиеся переходы получают ненулевую вероятность.
        $this->assertGreaterThan(0, $matrix['exhibition']['bar']);
        $this->assertSame(array_keys($matrix['exhibition'], max($matrix['exhibition']))[0], 'cafe');
        // Пустая строка — равномерное распределение.
        $this->assertEqualsWithDelta(1 / count($states), $matrix['bar']['park'], 1e-9);
    }

    public function test_new_user_falls_back_to_global_matrix(): void
    {
        foreach (range(1, 5) as $i) {
            $this->itinerary(User::factory()->create(), ['exhibition', 'cafe', 'concert']);
        }

        $newbie = User::factory()->create();
        $model = $this->markov->model($newbie);

        $this->assertSame(0.0, $model['lambda']['exhibition']);
        $this->assertEqualsWithDelta($model['global_matrix']['exhibition']['cafe'], $model['matrix']['exhibition']['cafe'], 1e-9);
        $this->assertSame('cafe', array_keys($model['matrix']['exhibition'], max($model['matrix']['exhibition']))[0]);
    }

    public function test_personal_history_shifts_prediction(): void
    {
        // Все остальные после выставки идут в кафе…
        foreach (range(1, 6) as $i) {
            $this->itinerary(User::factory()->create(), ['exhibition', 'cafe']);
        }

        // …а этот пользователь — в бар.
        $user = User::factory()->create();
        foreach (range(1, 8) as $i) {
            $this->itinerary($user, ['exhibition', 'bar']);
        }

        $this->markov->forgetGlobal();
        $p = $this->markov->nextDistribution($user, 'exhibition');

        $this->assertGreaterThan(0.5, $this->markov->model($user)['lambda']['exhibition']);
        $this->assertGreaterThan($p['cafe'], $p['bar']);
        $this->assertEqualsWithDelta(1.0, array_sum($p), 1e-9);
    }

    public function test_old_actions_weigh_less_than_recent(): void
    {
        $user = User::factory()->create();
        $this->itinerary($user, ['exhibition', 'cafe'], 120);
        $this->itinerary($user, ['exhibition', 'bar'], 1);

        $p = $this->markov->model($user)['personal_matrix']['exhibition'];

        $this->assertGreaterThan($p['cafe'], $p['bar']);
    }

    public function test_last_state_is_category_of_latest_choice(): void
    {
        $user = User::factory()->create();
        $this->itinerary($user, ['park', 'cafe'], 10);
        $this->itinerary($user, ['exhibition', 'theater'], 1);

        $this->assertSame('theater', $this->markov->lastState($user));
    }
}
