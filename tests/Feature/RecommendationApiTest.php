<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Itinerary;
use App\Models\User;
use App\Models\UserInteraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecommendationApiTest extends TestCase
{
    use RefreshDatabase;

    private const CITY = '7700000000000';

    protected function setUp(): void
    {
        parent::setUp();
        // В тестах внешние API недоступны — работаем на демо-данных.
        Cache::put('kudago:down', true, 600);
    }

    private function seedHistory(): void
    {
        foreach (range(1, 4) as $i) {
            $itinerary = Itinerary::create(['city_code' => self::CITY]);

            foreach (['exhibition', 'cafe', 'concert'] as $pos => $category) {
                $itinerary->stops()->create([
                    'event_id' => Event::factory()->category($category)->create()->id,
                    'position' => $pos + 1,
                ]);
            }
        }
    }

    public function test_events_fall_back_to_demo_source_when_kudago_is_down(): void
    {
        Event::factory()->category('exhibition')->create(['title' => 'Русский авангард']);
        Event::factory()->category('park')->create();

        $this->getJson('/api/v1/events?city=' . self::CITY . '&categories=exhibition&q=авангард')
            ->assertOk()
            ->assertJsonPath('source', 'demo')
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.category_name', 'Выставки');
    }

    public function test_recommendations_follow_markov_transition_from_draft(): void
    {
        $this->seedHistory();
        $exhibition = Event::factory()->category('exhibition')->create();
        $cafe = Event::factory()->category('cafe')->at((float) $exhibition->lat, (float) $exhibition->lng)->create();
        Event::factory()->category('bar')->at((float) $exhibition->lat, (float) $exhibition->lng)->create();

        $response = $this->postJson('/api/v1/recommendations', [
            'city_code' => self::CITY,
            'current_event_ids' => [$exhibition->id],
            'limit' => 5,
        ])->assertOk();

        $response->assertJsonPath('context.from_state', 'exhibition');
        $this->assertSame('cafe', $response->json('recommendations.0.event.category_group'));

        foreach ($response->json('recommendations') as $item) {
            $this->assertNotSame($exhibition->id, $item['event']['id']);

            foreach ($item['components'] as $value) {
                $this->assertGreaterThanOrEqual(0, $value);
                $this->assertLessThanOrEqual(1, $value);
            }
        }
    }

    public function test_budget_component_rejects_too_expensive(): void
    {
        Event::factory()->category('cafe')->priced(5000)->create();

        $response = $this->postJson('/api/v1/recommendations', ['city_code' => self::CITY, 'budget' => 1000])->assertOk();

        $this->assertSame(0, $response->json('recommendations.0.components.budget'));
    }

    public function test_profile_interests_raise_interest_score(): void
    {
        $user = User::factory()->create(['interests' => ['bar']]);
        Sanctum::actingAs($user);
        Event::factory()->category('bar')->create();
        Event::factory()->category('park')->create();

        $items = collect($this->postJson('/api/v1/recommendations', ['city_code' => self::CITY])->json('recommendations'))
            ->keyBy('event.category_group');

        $this->assertGreaterThan($items['park']['components']['interest'], $items['bar']['components']['interest']);
    }

    public function test_plan_builds_feasible_personalized_route(): void
    {
        $this->seedHistory();
        $date = now()->addDay()->toDateString();
        Event::factory()->category('exhibition')->create();
        Event::factory()->category('cafe')->create();
        Event::factory()->fixedAt($date . ' 19:00')->create();

        $response = $this->postJson('/api/v1/itineraries/plan', [
            'city_code' => self::CITY,
            'date' => $date,
            'start_time' => '12:00',
            'end_time' => '23:00',
            'stops' => 3,
            'start' => ['lat' => 55.75, 'lng' => 37.62],
        ])->assertOk();

        $variant = $response->json('variants.0');
        $this->assertCount(3, $variant['stops']);
        $this->assertSame('held-karp', $response->json('algorithm'));
        $this->assertCount(3, $response->json('explanations'));
        // Концерт с фиксированным началом в 19:00 — последним.
        $this->assertSame('19:00', collect($variant['stops'])->firstWhere('category_group', 'concert')['start']);
    }

    public function test_build_explains_events_on_other_days(): void
    {
        $a = Event::factory()->fixedAt(now()->addDays(2)->format('Y-m-d') . ' 19:00')->create();
        $b = Event::factory()->fixedAt(now()->addDays(3)->format('Y-m-d') . ' 19:00')->create();
        $c = Event::factory()->create();

        $response = $this->postJson('/api/v1/itineraries/build', ['event_ids' => [$a->id, $b->id, $c->id]])->assertOk();

        $this->assertCount(1, $response->json('skipped'));
        $this->assertStringContainsString('Проходит', $response->json('skipped.0.reason'));
        $this->assertCount(2, $response->json('variants.0.stops'));
    }

    public function test_saved_itinerary_keeps_chosen_order_and_logs_action(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $events = Event::factory()->count(3)->create();
        $order = [$events[2]->id, $events[0]->id, $events[1]->id];

        $this->postJson('/api/v1/itineraries', ['city_code' => self::CITY, 'event_ids' => $order])
            ->assertCreated()
            ->assertJsonPath('itinerary.stops.0.event.id', $order[0])
            ->assertJsonPath('itinerary.stops.2.event.id', $order[2]);

        $this->assertDatabaseHas('user_interactions', ['user_id' => $user->id, 'action' => UserInteraction::ACTION_SAVE_ROUTE]);
    }

    public function test_interactions_require_auth_and_store_category(): void
    {
        $event = Event::factory()->category('theater')->create();

        $this->postJson('/api/v1/interactions', ['action' => 'add_to_route', 'event_id' => $event->id])->assertUnauthorized();

        Sanctum::actingAs($user = User::factory()->create());
        $this->postJson('/api/v1/interactions', ['action' => 'add_to_route', 'event_id' => $event->id])->assertCreated();
        $this->postJson('/api/v1/interactions', ['action' => 'hack'])->assertUnprocessable();

        $this->assertDatabaseHas('user_interactions', ['user_id' => $user->id, 'category_group' => 'theater']);
    }

    public function test_profile_update_validates_interests(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/v1/profile', ['interests' => ['unknown']])->assertUnprocessable();
        $this->putJson('/api/v1/profile', ['interests' => ['cafe', 'concert'], 'default_budget' => 3000])
            ->assertOk()
            ->assertJsonPath('user.interests', ['cafe', 'concert'])
            ->assertJsonPath('user.default_budget', 3000);
    }

    public function test_markov_model_endpoint_returns_stochastic_matrix(): void
    {
        $this->seedHistory();

        $response = $this->getJson('/api/v1/recommendations/model')->assertOk();

        foreach ($response->json('matrix') as $row) {
            $this->assertEqualsWithDelta(1.0, array_sum($row), 0.01);
        }

        $this->assertCount(count(config('recommender.categories')), $response->json('states'));
    }
}
