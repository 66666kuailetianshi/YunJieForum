<?php
/**
 * 云界论坛 - PHP 扩展兼容层
 *
 * 当服务器未安装 mbstring 等扩展时，提供纯 PHP  fallback 实现。
 */

if (!extension_loaded('mbstring')) {
    if (!function_exists('mb_strlen')) {
        function mb_strlen(?string $string, ?string $encoding = null): int {
            $string = (string)$string;
            if ($string === '') {
                return 0;
            }
            $chars = @preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
            return $chars === false ? strlen($string) : count($chars);
        }
    }

    if (!function_exists('mb_substr')) {
        function mb_substr(?string $string, int $start, ?int $length = null, ?string $encoding = null): string {
            $string = (string)$string;
            if ($string === '') {
                return '';
            }

            $chars = @preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                return substr($string, $start, $length ?? strlen($string));
            }

            $count = count($chars);
            if ($start < 0) {
                $start = max(0, $count + $start);
            }
            if ($start >= $count) {
                return '';
            }

            if ($length === null) {
                $slice = array_slice($chars, $start);
            } else {
                $slice = array_slice($chars, $start, $length);
            }

            return implode('', $slice);
        }
    }

    if (!function_exists('mb_strpos')) {
        function mb_strpos(?string $haystack, ?string $needle, int $offset = 0, ?string $encoding = null) {
            $haystack = (string)$haystack;
            $needle = (string)$needle;
            if ($needle === '') {
                return false;
            }

            $chars = @preg_split('//u', $haystack, -1, PREG_SPLIT_NO_EMPTY);
            $needleChars = @preg_split('//u', $needle, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false || $needleChars === false) {
                return strpos($haystack, $needle, $offset);
            }

            $count = count($chars);
            $needleCount = count($needleChars);
            if ($offset < 0) {
                $offset = max(0, $count + $offset);
            }

            for ($i = $offset; $i <= $count - $needleCount; $i++) {
                $match = true;
                for ($j = 0; $j < $needleCount; $j++) {
                    if ($chars[$i + $j] !== $needleChars[$j]) {
                        $match = false;
                        break;
                    }
                }
                if ($match) {
                    return $i;
                }
            }

            return false;
        }
    }

    if (!function_exists('mb_strtolower')) {
        function mb_strtolower(?string $string, ?string $encoding = null): string {
            $string = (string)$string;
            if ($string === '') {
                return '';
            }
            // 拆分为 Unicode 字符数组后逐字符小写化
            $chars = @preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                return strtolower($string);
            }
            $result = '';
            foreach ($chars as $ch) {
                $lower = strtolower($ch);
                // strtolower 对多字节字符无效时保留原字符，再尝试常见中文/拉丁映射
                if ($lower === $ch && strlen($ch) > 1) {
                    // 基本拉丁扩展补充映射（覆盖常见西欧字符）
                    static $map = [
                        'À' => 'à','Á' => 'á','Â' => 'â','Ã' => 'ã','Ä' => 'ä','Å' => 'å','Æ' => 'æ',
                        'Ç' => 'ç','È' => 'è','É' => 'é','Ê' => 'ê','Ë' => 'ë','Ì' => 'ì','Í' => 'í',
                        'Î' => 'î','Ï' => 'ï','Ð' => 'ð','Ñ' => 'ñ','Ò' => 'ò','Ó' => 'ó','Ô' => 'ô',
                        'Õ' => 'õ','Ö' => 'ö','Ø' => 'ø','Ù' => 'ù','Ú' => 'ú','Û' => 'û','Ü' => 'ü',
                        'Ý' => 'ý','Þ' => 'þ','ß' => 'ss',
                    ];
                    $result .= $map[$ch] ?? $ch;
                } else {
                    $result .= $lower;
                }
            }
            return $result;
        }
    }

    if (!function_exists('mb_strtoupper')) {
        function mb_strtoupper(?string $string, ?string $encoding = null): string {
            $string = (string)$string;
            if ($string === '') {
                return '';
            }
            $chars = @preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                return strtoupper($string);
            }
            $result = '';
            foreach ($chars as $ch) {
                $upper = strtoupper($ch);
                if ($upper === $ch && strlen($ch) > 1) {
                    static $map = [
                        'à' => 'À','á' => 'Á','â' => 'Â','ã' => 'Ã','ä' => 'Ä','å' => 'Å','æ' => 'Æ',
                        'ç' => 'Ç','è' => 'È','é' => 'É','ê' => 'Ê','ë' => 'Ë','ì' => 'Ì','í' => 'Í',
                        'î' => 'Î','ï' => 'Ï','ð' => 'Ð','ñ' => 'Ñ','ò' => 'Ò','ó' => 'Ó','ô' => 'Ô',
                        'õ' => 'Õ','ö' => 'Ö','ø' => 'Ø','ù' => 'Ù','ú' => 'Ú','û' => 'Û','ü' => 'Ü',
                        'ý' => 'Ý','þ' => 'Þ',
                    ];
                    $result .= $map[$ch] ?? $ch;
                } else {
                    $result .= $upper;
                }
            }
            return $result;
        }
    }
}
