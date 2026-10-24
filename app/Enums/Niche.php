<?php

namespace App\Enums;

enum Niche: string
{
    case Skincare = 'skincare';
    case Haircare = 'haircare';
    case Supplements = 'supplements';
    case Fitness = 'fitness';
    case PetSupplies = 'pet-supplies';
    case HomeDecor = 'home-decor';
    case Kitchen = 'kitchen';
    case Apparel = 'apparel';
    case Jewelry = 'jewelry';
    case BabyKids = 'baby-kids';
    case Outdoor = 'outdoor';
    case TechAccessories = 'tech-accessories';

    public function displayName(): string
    {
        return match ($this) {
            self::Skincare => 'Skincare',
            self::Haircare => 'Haircare',
            self::Supplements => 'Supplements',
            self::Fitness => 'Fitness',
            self::PetSupplies => 'Pet Supplies',
            self::HomeDecor => 'Home Decor',
            self::Kitchen => 'Kitchen',
            self::Apparel => 'Apparel',
            self::Jewelry => 'Jewelry',
            self::BabyKids => 'Baby & Kids',
            self::Outdoor => 'Outdoor',
            self::TechAccessories => 'Tech Accessories',
        };
    }

    /**
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Skincare => ['serum', 'glow', 'SPF'],
            self::Haircare => ['shampoo', 'curls'],
            self::Supplements => ['protein', 'gummies'],
            self::Fitness => ['leggings', 'dumbbell'],
            self::PetSupplies => ['collar', 'kibble'],
            self::HomeDecor => ['sofa', 'candle'],
            self::Kitchen => ['blender', 'knife'],
            self::Apparel => ['hoodie', 'denim'],
            self::Jewelry => ['necklace', 'ring'],
            self::BabyKids => ['stroller', 'onesie'],
            self::Outdoor => ['tent', 'backpack'],
            self::TechAccessories => ['charger', 'phone case'],
        };
    }

    /**
     * Matches a slug or display name, case-insensitively and without trimming.
     */
    public static function fromSlugOrName(string $value): ?self
    {
        $needle = mb_strtolower($value);

        foreach (self::cases() as $case) {
            if ($needle === $case->value || $needle === mb_strtolower($case->displayName())) {
                return $case;
            }
        }

        return null;
    }
}
