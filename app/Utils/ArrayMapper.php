<?php

namespace App\Utils;

use Illuminate\Support\Facades\File;

class ArrayMapper
{
    protected $keyMapping = [];

    /**
     * Конструктор класса.
     *
     * @param string $mappingFilePath Путь к JSON-файлу с маппингом.
     */
    public function __construct(string $mappingFilePath)
    {
        // Загружаем маппинг из JSON-файла
        $this->loadKeyMapping($mappingFilePath);
    }

    /**
     * Загружает маппинг ключей из JSON-файла.
     *
     * @param string $filePath Путь к JSON-файлу.
     */
    protected function loadKeyMapping(string $filePath): void
    {
        if (!File::exists($filePath)) {
            throw new \Exception("Файл маппинга не найден: {$filePath}");
        }

        $content = File::get($filePath);
        $this->keyMapping = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Ошибка при декодировании JSON-файла: " . json_last_error_msg());
        }
    }

    /**
     * Стандартизирует ключи одного массива согласно маппингу.
     *
     * @param array $array Исходный массив.
     * @return array Стандартизованный массив.
     */
    public function standardizeKeys(array $array): array
    {
        $standardizedArray = [];

        foreach ($array as $key => $value) {
            if (isset($this->keyMapping[$key])) {
                $newKey = $this->keyMapping[$key];
                $standardizedArray[$newKey] = $value;
            } else {
                $standardizedArray[$key] = $value;
            }
        }

        return $standardizedArray;
    }

    /**
     * Применяет стандартизацию ключей ко всем внутренним массивам многомерного массива.
     *
     * @param array $multiDimensionalArray Многомерный массив.
     * @return array Многомерный массив с стандартизованными ключами.
     */
    public function standardizeMultiDimensionalArray(array $multiDimensionalArray): array
    {
        return array_map([$this, 'standardizeKeys'], $multiDimensionalArray);
    }
}