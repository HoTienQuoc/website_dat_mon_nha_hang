<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Dish;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            'khai-vi' => ['Khai vị', 'Starters', '#e8cba9'], 'mon-chinh' => ['Món chính', 'Main dishes', '#edb784'],
            'bun-pho' => ['Bún & phở', 'Noodles & pho', '#f0d6a4'], 'mon-chay' => ['Món chay', 'Vegetarian', '#bad6a5'],
            'thuc-uong' => ['Thức uống', 'Drinks', '#bbd7dd'], 'trang-mieng' => ['Tráng miệng', 'Desserts', '#dfbfd5'],
        ];
        $categories = [];
        foreach ($groups as $slug => [$name,$english,$color]) {
            $category = Category::firstOrCreate(['slug' => $slug], ['name' => $name, 'name_en' => $english]);
            if (! $category->name_en) {
                $category->update(['name_en' => $english]);
            }
            $categories[$slug] = $category;
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="420" viewBox="0 0 640 420"><rect width="640" height="420" fill="'.$color.'"/><ellipse cx="324" cy="238" rx="196" ry="125" fill="#422e201c"/><ellipse cx="320" cy="210" rx="192" ry="136" fill="#fffdf6"/><ellipse cx="320" cy="210" rx="156" ry="106" fill="#e9dfcb"/><ellipse cx="320" cy="210" rx="145" ry="96" fill="#f8f0dc"/><path d="M220 215q50-90 110-25t85 15q-45 105-160 32" fill="#bd653c"/><path d="M243 178q17-37 40-18m50 86q22-51 57-45m-153 35q-37-21-41 9" fill="none" stroke="#6a8c40" stroke-width="22" stroke-linecap="round"/><circle cx="365" cy="168" r="23" fill="#e5a843"/><circle cx="365" cy="168" r="11" fill="#ffdc80"/><path d="M150 63l-38 302m-19-308L70 357" stroke="#654129" stroke-width="9" stroke-linecap="round"/></svg>';
            if (! Storage::disk('public')->exists('seed/'.$slug.'.svg')) {
                Storage::disk('public')->put('seed/'.$slug.'.svg', $svg);
            }
        }
        $menu = [
            ['goi-cuon', 'khai-vi', 'Gỏi cuốn', 'Fresh spring rolls', 45000, 'Tôm, thịt và rau thơm cuốn bánh tráng; 4 cuốn kèm sốt đậu phộng.', 'Four rice-paper rolls with prawns, pork and herbs, served with peanut sauce.', 'Tôm, đậu phộng', 'Shellfish, peanuts', false, false],
            ['cha-gio', 'khai-vi', 'Chả giò', 'Crispy spring rolls', 50000, 'Chả giò nhân thịt, nấm và miến; 5 cuốn kèm rau sống.', 'Five crispy pork, mushroom and glass-noodle rolls with fresh greens.', 'Lúa mì, trứng', 'Wheat, eggs', false, false],
            ['goi-ngo-sen', 'khai-vi', 'Gỏi ngó sen tôm thịt', 'Lotus stem salad', 79000, 'Ngó sen giòn, tôm, thịt và rau răm với nước mắm chua ngọt.', 'Crunchy lotus stems, prawns, pork and herbs in a sweet-sour fish sauce dressing.', 'Tôm, cá, đậu phộng', 'Shellfish, fish, peanuts', false, true],
            ['com-ga', 'mon-chinh', 'Cơm gà', 'Chicken rice', 65000, 'Đùi gà áp chảo, cơm thơm, dưa leo và canh trong ngày.', 'Pan-seared chicken leg with fragrant rice, cucumber and the daily soup.', 'Đậu nành', 'Soy', false, false],
            ['bo-luc-lac', 'mon-chinh', 'Bò lúc lắc', 'Shaking beef', 95000, 'Bò xào lửa lớn với ớt chuông, hành tây; dùng kèm cơm.', 'Wok-seared beef with bell peppers and onions, served with rice.', 'Đậu nành, lúa mì', 'Soy, wheat', false, false],
            ['ca-kho-to', 'mon-chinh', 'Cá kho tộ', 'Clay-pot caramelised fish', 75000, 'Cá kho nước màu, tiêu và hành lá; kèm cơm trắng.', 'Fish simmered in caramel sauce with pepper and spring onions, with steamed rice.', 'Cá', 'Fish', false, true],
            ['suon-nuong', 'mon-chinh', 'Cơm sườn nướng', 'Grilled pork chop rice', 79000, 'Sườn ướp sả nướng, cơm tấm, đồ chua và nước mắm.', 'Lemongrass-grilled pork chop, broken rice, pickles and fish sauce.', 'Cá, đậu nành', 'Fish, soy', false, false],
            ['canh-chua', 'mon-chinh', 'Canh chua cá', 'Sour fish soup', 85000, 'Canh cá với thơm, cà chua, đậu bắp; phần dành cho 2 người.', 'Tangy fish soup with pineapple, tomato and okra; serves two.', 'Cá', 'Fish', false, true],
            ['pho-bo', 'bun-pho', 'Phở bò', 'Beef pho', 75000, 'Nước dùng hầm xương, bánh phở, bò tái và rau thơm.', 'Slow-simmered broth, rice noodles, sliced beef and fresh herbs.', 'Cá', 'Fish', false, false],
            ['bun-thit-nuong', 'bun-pho', 'Bún thịt nướng', 'Grilled pork vermicelli', 69000, 'Bún, thịt nướng, rau sống, đồ chua và đậu phộng.', 'Rice vermicelli with grilled pork, fresh greens, pickles and peanuts.', 'Cá, đậu phộng', 'Fish, peanuts', false, false],
            ['bun-bo-hue', 'bun-pho', 'Bún bò Huế', 'Hue-style spicy beef noodles', 79000, 'Bún sợi lớn, bò, chả và nước dùng sả cay.', 'Thick rice noodles, beef and pork sausage in a spicy lemongrass broth.', 'Tôm, cá', 'Shellfish, fish', false, true],
            ['dau-hu-nam', 'mon-chay', 'Đậu hũ sốt nấm', 'Tofu with mushroom sauce', 59000, 'Đậu hũ non, nấm đùi gà và nấm hương; kèm cơm trắng.', 'Silken tofu, king oyster and shiitake mushrooms, served with rice.', 'Đậu nành', 'Soy', true, false],
            ['com-chien-rau', 'mon-chay', 'Cơm chiên rau củ', 'Vegetable fried rice', 55000, 'Cơm chiên bắp, cà rốt, đậu Hà Lan; không dùng trứng.', 'Fried rice with corn, carrots and peas; prepared without eggs.', 'Đậu nành', 'Soy', true, false],
            ['tra-dao', 'thuc-uong', 'Trà đào', 'Peach iced tea', 30000, 'Trà đen ủ lạnh với đào, sả và cam; ly 500 ml.', 'Cold-brewed black tea with peach, lemongrass and orange; 500 ml.', null, null, true, false],
            ['ca-phe-sua', 'thuc-uong', 'Cà phê sữa đá', 'Vietnamese iced coffee', 35000, 'Cà phê phin đậm, sữa đặc và đá; ly 250 ml.', 'Bold phin-brewed coffee with condensed milk and ice; 250 ml.', 'Sữa', 'Milk', true, false],
            ['nuoc-chanh', 'thuc-uong', 'Nước chanh tươi', 'Fresh lemonade', 25000, 'Chanh tươi pha vừa ngọt, thêm đá; ly 400 ml.', 'Fresh lemon juice, lightly sweetened and served over ice; 400 ml.', null, null, true, false],
            ['che-hat-sen', 'trang-mieng', 'Chè hạt sen', 'Lotus seed sweet soup', 35000, 'Hạt sen nấu đường phèn và nhãn; dùng mát.', 'Lotus seeds and longan in a lightly sweet syrup, served chilled.', null, null, true, false],
            ['banh-flan', 'trang-mieng', 'Bánh flan', 'Caramel custard', 29000, 'Flan trứng sữa mềm, phủ caramel; 2 bánh nhỏ.', 'Two silky egg-and-milk custards topped with caramel.', 'Trứng, sữa', 'Eggs, milk', true, false],
        ];
        foreach ($menu as [$slug,$group,$name,$en,$price,$description,$descriptionEn,$allergens,$allergensEn,$vegetarian,$spicy]) {
            $dish = Dish::firstOrCreate(['slug' => $slug], ['category_id' => $categories[$group]->id, 'name' => $name, 'name_en' => $en, 'price' => $price, 'description' => $description, 'description_en' => $descriptionEn, 'allergens' => $allergens, 'allergens_en' => $allergensEn, 'is_vegetarian' => $vegetarian, 'is_spicy' => $spicy, 'image_path' => 'seed/'.$group.'.svg', 'is_available' => true]);
            $updates = [];
            foreach (['name_en' => $en, 'description_en' => $descriptionEn, 'allergens' => $allergens, 'allergens_en' => $allergensEn, 'image_path' => 'seed/'.$group.'.svg'] as $field => $value) {
                if ($dish->$field === null) {
                    $updates[$field] = $value;
                }
            }
            if ($dish->description === 'Món ngon quen thuộc, chuẩn bị tươi mỗi ngày.') {
                $updates['description'] = $description;
            }
            if ($updates) {
                $dish->update($updates);
            }
        }
    }
}
