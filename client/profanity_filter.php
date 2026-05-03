<?php
class ProfanityFilter
{
    private $badWords = [
        // English Primary
        'fuck',
        'shit',
        'asshole',
        'bitch',
        'cunt',
        'dick',
        'pussy',
        'motherfucker',
        'bastard',
        'slut',
        'whore',
        'faggot',
        'nigger',
        'piss',
        'cock',
        'twat',
        'wank',
        'ass',
        'damn',
        'hell',
        'arse',
        'balls',
        'crap',
        'darn',

        // English Variations & Compound
        'douchebag',
        'dumbass',
        'jackass',
        'asshat',
        'asswipe',
        'bullshit',
        'cocksucker',
        'dickhead',
        'dipshit',
        'fucktard',
        'nutbag',
        'prick',
        'pussywhip',
        'shithead',
        'shitstain',
        'skank',
        'whorebag',
        'bitchass',

        // English - Sexual/Vulgar
        'anal',
        'anus',
        'blowjob',
        'boner',
        'clit',
        'clitoris',
        'cumbucket',
        'cumdump',
        'cumslut',
        'dildo',
        'fap',
        'fellate',
        'fellatio',
        'foreskin',
        'handjob',
        'jizz',
        'jizm',
        'labia',
        'masturbate',
        'nipple',
        'penis',
        'porn',
        'porno',
        'scrotum',
        'semen',
        'shlong',
        'screw',
        'scrote',
        'smegma',
        'snatch',
        'taint',
        'testicle',
        'tits',
        'titties',
        'vagina',
        'vulva',
        'wang',
        'wanker',

        // Racial/Offensive Slurs
        'chink',
        'gook',
        'kike',
        'spic',
        'wetback',
        'cracker',
        'coon',
        'abbo',
        'raghead',
        'sandnigger',
        'slanteye',
        'zipperhead',

        // Tagalog/Filipino
        'putang ina',
        'putangina',
        'tangina',
        'tang ina',
        'gago',
        'tarantado',
        'kupal',
        'hayop',
        'bwisit',
        'pakyu',
        'pakshet',
        'punyeta',
        'leche',
        'bobo',
        'tite',
        'kiki',
        'puke',
        'bayag',
        'kantot',
        'iyot',
        'pekpek',
        'bilat',
        'burat',
        'etits',
        'jakol',
        'manyak',
        'ulol',
        'ulul',
        'vovo',
        'inutil',
        'tanga',
        'bungol',
        'sira ulo',
        'baliw',
        'luka-luka',
        'sinto-sinto',
        'gunggong',
        'bangag',
        'adik',
        'hampas lupa',
        'buwisit',
        'yawa',
        'yudipota',

        // Spanish (commonly used as curses)
        'puta',
        'cabron',
        'cojones',
        'joder',
        'mierda',
        'pinche',
        'verga',
        'carajo',
        'coño',
        'gilipollas',
        'capullo',
        'hijueputa',
        'marica',

        // Other International
        'cyka',
        'blyat',
        'kurwa',
        'perkele',
        'faen',
        'fitta',
        'satan',
        'helvete',
        'scheisse',
        'fick dich',
        'merde',
        'vaffanculo',

        // Common Misspellings/Leetspeak
        'fuk',
        'fack',
        'fuack',
        'phuk',
        'fak',
        'phuck',
        'fck',
        'fvck',
        'sh1t',
        'sh!t',
        'shiiit',
        'phuk',
        'b1tch',
        'b!tch',
        'c0ck',
        'd1ck',
        'p0rn',
        'pr0n',
        'n1gga',
        'nigg@',
        'f@g',
        'f4g',
        'wh0re',
        'wh03'
    ];

    private $patterns = [
        // Common leetspeak substitutions
        '/[4@]/i' => 'a',
        '/[3£€]/i' => 'e',
        '/[1!|]/i' => 'i',
        '/[0]/i' => 'o',
        '/[5$]/i' => 's',
        '/[7+]/i' => 't',
        '/[2]/i' => 'z',
        '/#/i' => 'h',
        '/@/' => 'a',
        '/\\$/' => 's',

        // Repeated characters
        '/(.)\\1{2,}/i' => '$1$1', // Reduce triple+ letters to double
        '/(.)\\1{1,}/i' => '$1$1',  // Double letters become single
    ];

    private $exceptions = [
        'assassin',
        'grass',
        'class',
        'bass',
        'brass',
        'mass',
        'shitake',
        'bitchute',
        'coconut',
        'analysis',
        'shuttle',
        'bitchin\'',
        'fucking' // Keep common positives
    ];

    public function filter($text, $level = 'normal')
    {
        if (empty($text)) return $text;

        $sortedWords = $this->badWords;
        usort($sortedWords, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($sortedWords as $word) {
            $replacement = str_repeat('*', strlen($word));
            // Case-insensitive match but preserves the rest of the string's case
            $text = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', $replacement, $text);
        }

        // Simple leetspeak fallback if level is strict/maximum
        if ($level === 'strict' || $level === 'maximum') {
            $leetspeak = [
                'fuk' => '***', 'fck' => '***', 'sh1t' => '****', 'p0rn' => '****'
            ];
            foreach ($leetspeak as $bad => $good) {
                $text = preg_replace('/\b' . preg_quote($bad, '/') . '\b/i', $good, $text);
            }
        }

        return $text;
    }

    private function normalizeText($text)
    {
        // Apply leetspeak conversion
        foreach ($this->patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        // Remove common separators (dots, underscores, hyphens between letters)
        $text = preg_replace('/([a-z])[._\-]([a-z])/i', '$1$2', $text);

        // Convert to lowercase for matching
        $text = strtolower($text);

        return $text;
    }

    private function isException($word, $original)
    {
        $originalLower = strtolower($original);
        foreach ($this->exceptions as $exception) {
            if (
                strpos($originalLower, $exception) !== false &&
                strpos($exception, $word) !== false
            ) {
                return true;
            }
        }
        return false;
    }

    private function getReplacement($word, $level)
    {
        switch ($level) {
            case 'light':
                return str_repeat('*', max(1, ceil(strlen($word) * 0.5)));
            case 'normal':
            case 'strict':
            case 'maximum':
                return str_repeat('*', strlen($word));
            default:
                return str_repeat('*', strlen($word));
        }
    }

    private function aggressiveFilter($text)
    {
        // Catch patterns where words are concatenated or spread out
        $aggressivePatterns = [
            '/f\s*u\s*c\s*k/i' => '****',
            '/s\s*h\s*i\s*t/i' => '****',
            '/b\s*i\s*t\s*c\s*h/i' => '*****',
            '/c\s*u\s*n\s*t/i' => '****',
            '/d\s*i\s*c\s*k/i' => '****',
            '/p\s*u\s*s\s*s\s*y/i' => '*****',
            '/n\s*i\s*g\s*g\s*e\s*r/i' => '******',
        ];

        foreach ($aggressivePatterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    // Check if text contains profanity (returns boolean)
    public function containsProfanity($text, $threshold = 0)
    {
        $filtered = $this->filter($text);

        if ($threshold > 0) {
            $originalLength = strlen($text);
            $filteredLength = strlen($filtered);
            $difference = $originalLength - $filteredLength;
            return $difference >= $threshold;
        }

        return $text !== $filtered;
    }

    // Get severity score (higher = more severe)
    public function getSeverityScore($text)
    {
        $score = 0;
        $original = $text;
        $text = $this->normalizeText($text);

        foreach ($this->badWords as $word) {
            if (stripos($text, $word) !== false) {
                // Longer words get higher score
                $score += strlen($word);

                // Severe words get bonus
                $severeWords = ['nigger', 'cunt', 'motherfucker', 'faggot', 'rape'];
                if (in_array(strtolower($word), $severeWords)) {
                    $score += 10;
                }
            }
        }

        return min(100, $score);
    }
}

// Easy-to-use function version
function filterProfanity($text, $level = 'normal')
{
    $filter = new ProfanityFilter();
    return $filter->filter($text, $level);
}

// Check if text contains profanity
function containsProfanity($text)
{
    $filter = new ProfanityFilter();
    return $filter->containsProfanity($text);
}

// Get severity score
function getProfanityScore($text)
{
    $filter = new ProfanityFilter();
    return $filter->getSeverityScore($text);
}


?>