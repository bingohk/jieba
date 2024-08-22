<?php
/**
 * Jieba.php
 *
 * PHP version 8.2+
 *
 * @category PHP
 * @package  /src/class/
 * @author   Fukuball Lin <fukuball@gmail.com>
 * @license  MIT Licence
 * @version  GIT: <fukuball/jieba-php>
 * @link     https://github.com/fukuball/jieba-php
 */

namespace Bingohk\Jieba;

use Fukuball\Tebru\MultiArray;

define("MIN_FLOAT", -3.14e+100);

/**
 * Jieba
 *
 * @category PHP
 * @package  /src/class/
 * @author   Fukuball Lin <fukuball@gmail.com>
 * @license  MIT Licence
 * @version  Release: <0.16>
 * @link     https://github.com/fukuball/jieba-php
 */
class Jieba
{
    public static float $total = 0.0;
    public static array $trie = [];
    public static array $FREQ = [];
    public static array $original_freq = [];
    public static float $min_freq = 0.0;
    public static array $route = [];
    public static string $dictname = '';
    public static array $user_dictname = [];
    public static bool $cjk_all = false;
    public static array $dag_cache = [];

    /**
     * Static method init
     *
     * @param array $options # other options
     *
     * @return void
     */
    public static function init(array $options = []): void
    {
        $defaults = [
            'mode'=>'default',
            'dict'=>'normal',
            'cjk'=>'chinese'
        ];

        $options = array_merge($defaults, $options);

        if ($options['mode'] == 'test') {
            echo "Building Trie...\n";
        }

        $f_name = match ($options['dict']) {
            'small' => 'dict.small.txt',
            'big' => 'dict.big.txt',
            default => 'dict.txt',
        };

        self::$dictname = $f_name;

        self::$cjk_all = $options['cjk'] == 'all';

        $t1 = microtime(true);
        self::$dag_cache = [];
        self::$trie = self::genTrie(dirname(dirname(__FILE__))."/dict/".$f_name);
        self::__calcFreq();

        if ($options['mode'] == 'test') {
            echo "Loading model cost ".(microtime(true) - $t1)." seconds.\n";
            echo "Trie has been built successfully.\n";
        }
    }

    /**
     * Static method __calcFreq
     *
     * @return void
     */
    public static function __calcFreq(): void
    {
        foreach (self::$original_freq as $key => $value) {
            self::$FREQ[$key] = log($value / self::$total);
        }
        self::$min_freq = min(self::$FREQ);
    }

    /**
     * Static method calc
     *
     * @param string $sentence # input sentence
     * @param array  $DAG      # DAG
     * @param array  $options  # other options
     *
     * @return array self::$route
     */
    public static function calc(string $sentence, array $DAG, array $options = []): array
    {
        $N = mb_strlen($sentence, 'UTF-8');
        self::$route = [];
        self::$route[$N] = [$N => 0.0];
        for ($i = ($N - 1); $i >= 0; $i--) {
            $candidates = [];
            foreach ($DAG[$i] as $x) {
                $w_c = mb_substr($sentence, $i, (($x + 1) - $i), 'UTF-8');
                $previous_freq = current(self::$route[$x + 1]);
                $current_freq = (float) ($previous_freq + (self::$FREQ[$w_c] ?? self::$min_freq));
                $candidates[$x] = $current_freq;
            }
            arsort($candidates);
            $max_prob = reset($candidates);
            $max_key = key($candidates);
            self::$route[$i] = [$max_key => $max_prob];
        }

        return self::$route;
    }

    /**
     * Static method genTrie
     *
     * @param string $f_name  # input f_name
     * @param array  $options # other options
     *
     * @return array self::$trie
     */
    public static function genTrie(string $f_name, array $options = []): MultiArray
    {
        $defaults = ['mode' => 'default'];

        $options = array_merge($defaults, $options);

        self::$trie = new MultiArray(file_get_contents($f_name.'.json'));

        $content = fopen($f_name, 'r');
        if ($content === false) {
            throw new \RuntimeException("Could not open the file: $f_name");
        }

        while (($line = fgets($content)) !== false) {
            $explode_line = explode(" ", trim($line));
            $word = $explode_line[0];
            $freq = (float) $explode_line[1];

            if (isset(self::$original_freq[$word])) {
                self::$total -= self::$original_freq[$word];
            }

            self::$original_freq[$word] = $freq;
            self::$total += $freq;
        }

        fclose($content);

        return self::$trie;
    }

    /**
     * Static method loadUserDict
     *
     * @param string $f_name  # input f_name
     * @param array  $options # other options
     *
     * @return array self::$trie
     */
    public static function loadUserDict(string $f_name, array $options = []): MultiArray
    {
        self::$user_dictname[] = $f_name;
        $content = fopen($f_name, 'r');
        if ($content === false) {
            throw new \RuntimeException("Could not open the file: $f_name");
        }

        while (($line = fgets($content)) !== false) {
            $explode_line = explode(" ", trim($line));
            $word = $explode_line[0];
            $freq = isset($explode_line[1]) ? (float) $explode_line[1] : 1.0;

            if (isset(self::$original_freq[$word])) {
                self::$total -= self::$original_freq[$word];
            }

            self::$original_freq[$word] = $freq;
            self::$total += $freq;

            $l = mb_strlen($word, 'UTF-8');
            $word_c = [];
            for ($i = 0; $i < $l; $i++) {
                $word_c[] = mb_substr($word, $i, 1, 'UTF-8');
            }

            $word_c_key = implode('.', $word_c);
            self::$trie->set($word_c_key, ["end" => ""]);
        }

        fclose($content);
        self::__calcFreq();
        self::$dag_cache = [];

        return self::$trie;
    }

    /**
     * Static method addWord
     *
     * @param string $word
     * @param float  $freq
     * @param string $tag
     *
     * @return array self::$trie
     */
    public static function addWord(string $word, float $freq, string $tag = '', array $options = []): MultiArray
    {
        if (isset(self::$original_freq[$word])) {
            self::$total -= self::$original_freq[$word];
        }

        self::$original_freq[$word] = $freq;
        self::$total += $freq;

        $l = mb_strlen($word, 'UTF-8');
        $word_c = [];
        for ($i = 0; $i < $l; $i++) {
            $word_c[] = mb_substr($word, $i, 1, 'UTF-8');
        }

        $word_c_key = implode('.', $word_c);
        self::$trie->set($word_c_key, ["end" => ""]);
        self::__calcFreq();
        self::$dag_cache = [];

        return self::$trie;
    }

    /**
     * Static method tokenize
     *
     * @param string $sentence
     *
     * @return array
     */
    public static function tokenize($sentence, $options = array("HMM" => true))
    {
        $seg_list = self::cut($sentence, false, array("HMM" => $options["HMM"]));
        $tokenize_list = [];
        $start = 0;
        $end = 0;
        foreach ($seg_list as $seg) {
            $end = $start+mb_strlen($seg, 'UTF-8');
            $tokenize = [
                'word' => $seg,
                'start' => $start,
                'end' => $end
            ];
            $start = $end;
            $tokenize_list[] = $tokenize;
        }
        return $tokenize_list;
    }

    /**
     * Static method __cutAll
     *
     * @param string $sentence # input sentence
     * @param array  $options  # other options
     *
     * @return array $words
     */
    public static function __cutAll($sentence, $options = array())
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $words = array();

        $DAG = self::getDAG($sentence);
        $old_j = -1;

        foreach ($DAG as $k => $L) {
            if (count($L) == 1 && $k > $old_j) {
                $word = mb_substr($sentence, $k, (($L[0]-$k)+1), 'UTF-8');
                $words[] = $word;
                $old_j = $L[0];
            } else {
                foreach ($L as $j) {
                    if ($j > $k) {
                        $word = mb_substr($sentence, $k, ($j-$k)+1, 'UTF-8');
                        $words[] = $word;
                        $old_j = $j;
                    }
                }
            }
        }

        return $words;
    }// end function __cutAll

    /**
     * Static method getDAG
     *
     * @param string $sentence # input sentence
     * @param array  $options  # other options
     *
     * @return array $DAG
     */
    public static function getDAG($sentence, $options = array())
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $N = mb_strlen($sentence, 'UTF-8');
        $i = 0;
        $j = 0;
        $DAG = array();
        $word_c = array();

        while ($i < $N) {
            $c = mb_substr($sentence, $j, 1, 'UTF-8');
            if (count($word_c)==0) {
                $next_word_key = $c;
            } else {
                $next_word_key = implode('.', $word_c).'.'.$c;
            }

            if (isset(self::$dag_cache[$next_word_key])) {
                if (self::$dag_cache[$next_word_key]['exist']) {
                    $word_c[] = $c;
                    if (self::$dag_cache[$next_word_key]['end']) {
                        if (!isset($DAG[$i])) {
                            $DAG[$i] = array();
                        }
                        $DAG[$i][] = $j;
                    }
                    $j += 1;
                    if ($j >= $N) {
                        $word_c = array();
                        $i += 1;
                        $j = $i;
                    }
                } else {
                    $word_c = array();
                    $i += 1;
                    $j = $i;
                }
                continue;
            }

            if (self::$trie->exists($next_word_key)) {
                self::$dag_cache[$next_word_key] = array('exist' => true, 'end' => false);
                $word_c[] = $c;
                $next_word_key_value = self::$trie->get($next_word_key);
                if ($next_word_key_value == array("end"=>"")
                 || isset($next_word_key_value["end"])
                 || isset($next_word_key_value[0]["end"])
                ) {
                    self::$dag_cache[$next_word_key]['end'] = true;
                    if (!isset($DAG[$i])) {
                        $DAG[$i] = array();
                    }
                    $DAG[$i][] = $j;
                }
                $j += 1;
                if ($j >= $N) {
                    $word_c = array();
                    $i += 1;
                    $j = $i;
                }
            } else {
                $word_c = array();
                $i += 1;
                $j = $i;
                self::$dag_cache[$next_word_key] = array('exist' => false);
            }
        }

        for ($i=0; $i<$N; $i++) {
            if (!isset($DAG[$i])) {
                $DAG[$i] = array($i);
            }
        }

        return $DAG;
    }// end function getDAG

    /**
     * Static method __cutDAG
     *
     * @param string $sentence # input sentence
     * @param array  $options  # other options
     *
     * @return array $words
     */
    public static function __cutDAG($sentence, $options = array())
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $words = array();

        $N = mb_strlen($sentence, 'UTF-8');
        $DAG = self::getDAG($sentence);

        self::calc($sentence, $DAG);

        $x = 0;
        $buf = '';

        while ($x < $N) {
            $current_route_keys = array_keys(self::$route[$x]);
            $y = $current_route_keys[0]+1;
            $l_word = mb_substr($sentence, $x, ($y-$x), 'UTF-8');

            if (($y-$x)==1) {
                $buf = $buf.$l_word;
            } else {
                if (mb_strlen($buf, 'UTF-8')>0) {
                    if (mb_strlen($buf, 'UTF-8')==1) {
                        $words[] = $buf;
                        $buf = '';
                    } else {
                        if (! isset(self::$FREQ[$buf])) {
                            $regognized = Finalseg::cut($buf);
                            foreach ($regognized as $key => $word) {
                                $words[] = $word;
                            }
                        } else {
                            $elem_array = preg_split('//u', $buf, -1, PREG_SPLIT_NO_EMPTY);
                            foreach ($elem_array as $word) {
                                $words[] = $word;
                            }
                        }
                        $buf = '';
                    }
                }
                $words[] = $l_word;
            }
            $x = $y;
        }

        if (mb_strlen($buf, 'UTF-8')>0) {
            if (mb_strlen($buf, 'UTF-8')==1) {
                $words[] = $buf;
            } else {
                if (! isset(self::$FREQ[$buf])) {
                    $regognized = Finalseg::cut($buf);
                    foreach ($regognized as $key => $word) {
                        $words[] = $word;
                    }
                } else {
                    $elem_array = preg_split('//u', $buf, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($elem_array as $word) {
                        $words[] = $word;
                    }
                }
            }
        }

        return $words;
    }// end function __cutDAG

    /**
     * Static method __cutDAGNoHMM
     *
     * @param string $sentence # input sentence
     * @param array  $options  # other options
     *
     * @return array $words
     */
    public static function __cutDAGNoHMM($sentence, $options = array())
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $words = array();

        $N = mb_strlen($sentence, 'UTF-8');
        $DAG = self::getDAG($sentence);

        self::calc($sentence, $DAG);

        $x = 0;
        $buf = '';

        $re_eng_pattern = '[a-zA-Z+#]+';

        while ($x < $N) {
            $current_route_keys = array_keys(self::$route[$x]);
            $y = $current_route_keys[0]+1;
            $l_word = mb_substr($sentence, $x, ($y-$x), 'UTF-8');

            if (preg_match('/'.$re_eng_pattern.'/u', $l_word)) {
                $buf = $buf.$l_word;
                $x = $y;
            } else {
                if (mb_strlen($buf, 'UTF-8')>0) {
                    $words[] = $buf;
                    $buf = '';
                }
                $words[] = $l_word;
                $x = $y;
            }
        }

        if (mb_strlen($buf, 'UTF-8')>0) {
            $words[] = $buf;
            $buf = '';
        }

        return $words;
    }// end function __cutDAGNoHMM

    /**
     * Static method cut
     *
     * @param string  $sentence # input sentence
     * @param boolean $cut_all  # cut_all or not
     * @param array   $options  # other options
     *
     * @return array $seg_list
     */
    public static function cut($sentence, $cut_all = false, $options = array("HMM" => true))
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $seg_list = array();

        $re_han_pattern = '([\x{4E00}-\x{9FA5}]+)';
        $re_han_with_ascii_pattern = '([\x{4E00}-\x{9FA5}a-zA-Z0-9+#&=\._]+)';
        $re_kanjikana_pattern = '([\x{3040}-\x{309F}\x{4E00}-\x{9FA5}]+)';
        $re_katakana_pattern = '([\x{30A0}-\x{30FF}]+)';
        $re_hangul_pattern = '([\x{AC00}-\x{D7AF}]+)';
        $re_ascii_pattern = '([a-zA-Z0-9+#&=\._\r\n]+)';
        $re_skip_pattern = '(\s+)';
        if ($cut_all) {
            $re_skip_pattern = '([a-zA-Z0-9+#&=\._\r\n]+)';
        }
        $re_punctuation_pattern = '([\x{ff5e}\x{ff01}\x{ff08}\x{ff09}\x{300e}'.
                                    '\x{300c}\x{300d}\x{300f}\x{3001}\x{ff1a}\x{ff1b}'.
                                    '\x{ff0c}\x{ff1f}\x{3002}]+)';

        if (self::$cjk_all) {
            $filter_pattern = $re_kanjikana_pattern.
                            '|'.$re_katakana_pattern.
                            '|'.$re_hangul_pattern;
        } else {
            $filter_pattern = $re_han_with_ascii_pattern;
        }

        preg_match_all(
            '/('.$filter_pattern.'|'.$re_ascii_pattern.'|'.$re_punctuation_pattern.')/u',
            $sentence,
            $matches,
            PREG_PATTERN_ORDER
        );
        $blocks = $matches[0];

        foreach ($blocks as $blk) {
            if (mb_strlen($blk, 'UTF-8')==0) {
                continue;
            }
            if (self::$cjk_all) {
                // skip korean
                $filter_pattern = $re_kanjikana_pattern.'|'.$re_katakana_pattern;
            } else {
                $filter_pattern = $re_han_with_ascii_pattern;
            }

            if (preg_match('/'.$filter_pattern.'/u', $blk)) {
                if ($cut_all) {
                    $words = Jieba::__cutAll($blk);
                } else {
                    if ($options['HMM']) {
                        $words = Jieba::__cutDAG($blk);
                    } else {
                        $words = Jieba::__cutDAGNoHMM($blk);
                    }
                }

                foreach ($words as $word) {
                    $seg_list[] = $word;
                }
            } elseif (preg_match('/'.$re_skip_pattern.'/u', $blk)) {
                preg_match_all(
                    '/('.$re_skip_pattern.')/u',
                    $blk,
                    $tmp,
                    PREG_PATTERN_ORDER
                );
                $tmp = $tmp[0];
                foreach ($tmp as $x) {
                    if (preg_match('/'.$re_skip_pattern.'/u', $x)) {
                        if (str_replace(' ', '', $x) != '') {
                            $seg_list[] = $x;
                        }
                    } else {
                        if (!$cut_all) {
                            $xx_array = preg_split('//u', $x, -1, PREG_SPLIT_NO_EMPTY);
                            foreach ($xx_array as $xx) {
                                $seg_list[] = $xx;
                            }
                        } else {
                            $seg_list[] = $x;
                        }
                    }
                }
            } elseif (preg_match('/'.$re_punctuation_pattern.'/u', $blk)) {
                $seg_list[] = $blk;
            }// end else (preg_match('/'.$re_han_pattern.'/u', $blk))
        }// end foreach ($blocks as $blk)

        return $seg_list;
    }// end function cut

    /**
     * Static method cutForSearch
     *
     * @param string  $sentence # input sentence
     * @param array   $options  # other options
     *
     * @return array $seg_list
     */
    public static function cutForSearch($sentence, $options = array("HMM" => true))
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $seg_list = array();

        $cut_seg_list = Jieba::cut($sentence, false, array("HMM" => $options["HMM"]));

        foreach ($cut_seg_list as $w) {
            $len = mb_strlen($w, 'UTF-8');

            if ($len>2) {
                for ($i=0; $i<($len-1); $i++) {
                    $gram2 = mb_substr($w, $i, 2, 'UTF-8');

                    if (isset(self::$FREQ[$gram2])) {
                        $seg_list[] = $gram2;
                    }
                }
            }

            if (mb_strlen($w, 'UTF-8')>3) {
                for ($i=0; $i<($len-2); $i++) {
                    $gram3 = mb_substr($w, $i, 3, 'UTF-8');

                    if (isset(self::$FREQ[$gram3])) {
                        $seg_list[] = $gram3;
                    }
                }
            }

            $seg_list[] = $w;
        }

        return $seg_list;
    }// end function cutForSearch
}// end of class Jieba
