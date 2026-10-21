<?php

declare(strict_types=1);

namespace App\Money\Entity;

use App\Money\Scope;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'money_category')]
class Category
{
    use Scoped;

    public const DEFAULTS = [
        'expense' => [
            '🛒 Продукты', '🍔 Кафе и рестораны', '🚕 Транспорт', '🏠 Жильё и ЖКХ', '📱 Связь и интернет',
            '💊 Здоровье', '👕 Одежда', '🎉 Развлечения', '🎁 Подарки', '📺 Подписки', '🚗 Авто',
            '📚 Образование', '🐾 Питомцы', '✈️ Путешествия', '💸 Прочее',
        ],
        'income' => ['💼 Зарплата', '🧑‍💻 Подработка', '🎁 Подарки', '📈 Проценты и кэшбэк', '💰 Прочее'],
    ];

    #[ORM\Id, ORM\Column(type: UuidType::NAME)]
    public Uuid $id;

    #[ORM\Column]
    public bool $archived = false;

    public function __construct(
        #[ORM\Column(length: 10)]
        public string $kind = 'expense',
        #[ORM\Column(length: 100)]
        public string $name = '',
        #[ORM\Column(length: 16)]
        public string $icon = '',
    ) {
        $this->id = Uuid::v7();
    }

    /** @return list<self> */
    public static function defaults(Scope $scope): array
    {
        $out = [];
        foreach (self::DEFAULTS as $kind => $names) {
            foreach ($names as $n) {
                [$icon, $name] = explode(' ', $n, 2);
                $out[] = (new self($kind, $name, $icon))->setScope($scope);
            }
        }

        return $out;
    }

    public function label(): string
    {
        return trim($this->icon."\u{2002}".$this->name);
    }
}
