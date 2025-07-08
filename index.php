<?php

session_start();

if (!isset($_SESSION['visit_logged'])) {
    $_SESSION['visit_logged'] = true;

    $log_file = 'access.csv';
    file_put_contents($log_file, date('Y-m-d H:i:s') . " ; " . $_SERVER['REMOTE_ADDR'] . " ; " . $_SERVER['HTTP_USER_AGENT'] . PHP_EOL, FILE_APPEND);
}


$default_length = 10;
$result = null;

// Если длина пришла из формы — сохранить в cookie
if (isset($_POST['length']) && is_numeric($_POST['length'])) {
    $length = max(10, intval($_POST['length']));
    setcookie('password_length', $length, time() + 60 * 60 * 24 * 600); // 600 дней
} elseif (isset($_COOKIE['password_length']) && is_numeric($_COOKIE['password_length'])) {
    $length = max(10, intval($_COOKIE['password_length']));
} else {
    $length = $default_length;
}


function load_words($file) {
    return file_exists($file) ? array_map('trim', file($file)) : [];
}

function capitalize_one_word($words) {
    $index = random_int(0, count($words) - 1);
    $words[$index] = mb_strtoupper(mb_substr($words[$index], 0, 1)) . mb_substr($words[$index], 1);
    return $words;
}

function russian_keyboard_simulation($text) {
    $layout = [
        'а'=>'f','б'=>',','в'=>'d','г'=>'u','д'=>'l','е'=>'t','ё'=>'`','ж'=>';','з'=>'p','и'=>'b','й'=>'q',
        'к'=>'r','л'=>'k','м'=>'v','н'=>'y','о'=>'j','п'=>'g','р'=>'h','с'=>'c','т'=>'n','у'=>'e','ф'=>'a',
        'х'=>'[','ц'=>'w','ч'=>'x','ш'=>'i','щ'=>'o','ъ'=>']','ы'=>'s','ь'=>'m','э'=>"'",'ю'=>'.','я'=>'z',
        'А'=>'F','Б'=>'<','В'=>'D','Г'=>'U','Д'=>'L','Е'=>'T','Ё'=>'~','Ж'=>':','З'=>'P','И'=>'B','Й'=>'Q',
        'К'=>'R','Л'=>'K','М'=>'V','Н'=>'Y','О'=>'J','П'=>'G','Р'=>'H','С'=>'C','Т'=>'N','У'=>'E','Ф'=>'A',
        'Х'=>'{','Ц'=>'W','Ч'=>'X','Ш'=>'I','Щ'=>'O','Ъ'=>'}','Ы'=>'S','Ь'=>'M','Э'=>'"','Ю'=>'>','Я'=>'Z'
    ];
    return implode('', array_map(function($char) use ($layout) {
        return $layout[$char] ?? $char;
    }, mb_str_split($text)));
}

function generate_password($length = 10, $wordListFile = 'words.txt') {
    $words = load_words($wordListFile);
    if (count($words) < 100) return ["error" => "Недостаточно слов в словаре."];

    $targetLength = max(10, intval($length));
    $symbols = str_split("!@#%^&*-_?");
    $symbol = $symbols[random_int(0, count($symbols) - 1)];
    $digit = strval(random_int(0, 9));

    $extraLen = mb_strlen($symbol . $digit);
    $wordsPartLength = $targetLength - $extraLen;

    // Предварительно отфильтруем слова
    $filteredWords = array_filter($words, function($w) use ($wordsPartLength) {
        $len = mb_strlen($w);
        return $len >= 2 && $len <= $wordsPartLength;
    });
    $filteredWords = array_values($filteredWords);

    $maxTries = 1000;
    $found = false;

    for ($i = 0; $i < $maxTries; $i++) {
        $selected = [];
        $sumLen = 0;

        while ($sumLen < $wordsPartLength) {
            $w = $filteredWords[random_int(0, count($filteredWords) - 1)];
            $wLen = mb_strlen($w);

            if ($sumLen + $wLen > $wordsPartLength) break;

            $selected[] = $w;
            $sumLen += $wLen;
        }

        if ($sumLen === $wordsPartLength && count($selected) >= 2) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        return ["error" => "Не удалось подобрать слова для пароля длиной $length"];
    }

    $wordsWithCap = capitalize_one_word($selected);
    $wordCount = count($wordsWithCap);
    $result = [];

    if ($wordCount == 2) {
        // Варианты вставки цифры и символа
        $result[] = $digit;
        $result[] = $wordsWithCap[0];
        $result[] = $symbol;
        $result[] = $wordsWithCap[1];
    } else {
        $inserted = 0;
        $positions = range(1, $wordCount - 1);
        shuffle($positions);
        $digitPos = $positions[0];
        $symbolPos = $positions[1] ?? $positions[0];

        foreach ($wordsWithCap as $i => $w) {
            $result[] = $w;
            if ($i + 1 === $digitPos) $result[] = $digit;
            if ($i + 1 === $symbolPos) $result[] = $symbol;
        }
    }

    $password = implode("", $result);
    $keyboard = russian_keyboard_simulation($password);

    return [
        'password' => $password,
        'words_used' => $selected,
        'digit' => $digit,
        'symbol' => $symbol,
        'keyboard' => $keyboard,
        'keyboard_length' => mb_strlen($keyboard)
    ];
}


// Сохраняем длину в сессию
if (!isset($_SESSION['password_length'])) {
    $_SESSION['password_length'] = 10;
}

if (isset($_POST['length']) && is_numeric($_POST['length'])) {
    $_SESSION['password_length'] = max(8, intval($_POST['length']));
}

if (isset($_POST['generate'])) {
    $result = generate_password($length);
}

?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Генератор запоминающихся паролей</title>
    <style>
        code { background: #f0f0f0; padding: 2px 4px; }
        input[type="number"] { width: 60px; }
        button { margin-top: 10px; }
    </style>
</head>
<body>
    <h2>Генератор запоминающихся паролей</h2>

	<form method="post">
		<label>Длина (≥10): <input type="number" name="length" value="<?php echo $length; ?>" min="10"></label>
		<button type="submit" name="generate">Сгенерировать</button>
	</form>


<?php if (isset($result)): ?>
    <?php if (!isset($result['error'])): ?>
        <p>
            <strong>Слова:</strong> <code><?= implode(", ", $result['words_used']) ?></code> +
            <strong>цифра:</strong> <code><?= $result['digit'] ?></code>,
            <strong>символ:</strong> <code><?= $result['symbol'] ?></code>,
            <strong>Итог:</strong> <code><?= $result['password'] ?></code>
        </p>
        <p>
            <strong>Пароль:</strong>
            <input type="text" id="keyboard" value="<?= htmlspecialchars($result['keyboard']) ?>" readonly size="40">
            <button onclick="copyToClipboard()">Копировать</button>
            <em>(<?= $result['keyboard_length'] ?> символов)</em>
        </p>
    <?php else: ?>
        <p style="color:red"><?= htmlspecialchars($result['error']) ?></p>
    <?php endif; ?>
<?php endif; ?>

    <script>
        function copyToClipboard() {
            const input = document.getElementById("keyboard");
            input.select();
            document.execCommand("copy");
        }
    </script>
	<pre>   </pre>
	В этой реализации генератора пароль, для облегчения запоминания, составляется из нескольких русских слов.
	При вводе включаете английскую раскладку и, глядя на русские буквы, вводите пароль.

</body>
</html>