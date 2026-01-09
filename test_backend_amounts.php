<?php
/**
 * Test completo para las funciones de parseo y formateo de montos
 * Sistema LATAM - Formato latino (1.234.567,89)
 */

require_once __DIR__ . '/config/amount_utils.php';

// Colores para terminal
function colorize($text, $status) {
    $colors = [
        'success' => "\033[0;32m",
        'error' => "\033[0;31m",
        'info' => "\033[0;36m",
        'reset' => "\033[0m"
    ];
    return $colors[$status] . $text . $colors['reset'];
}

// Constantes para tolerancia de comparación de floats
define('FLOAT_TOLERANCE_ABSOLUTE', 0.0001);  // Para valores pequeños (<1)
define('FLOAT_TOLERANCE_RELATIVE', 0.0001);  // 0.01% para valores grandes

// Función de prueba
function test($description, $input, $expected_parse, $expected_format = null) {
    static $test_number = 0;
    $test_number++;
    
    // Test parseMontoLatino
    $result_parse = parseMontoLatino($input);
    
    // Usar tolerancia relativa para comparación de floats
    // Para valores pequeños (<1), usar tolerancia absoluta
    // Para valores grandes, usar tolerancia relativa
    $tolerance = ($expected_parse < 1.0) 
        ? FLOAT_TOLERANCE_ABSOLUTE 
        : abs($expected_parse * FLOAT_TOLERANCE_RELATIVE);
    $parse_ok = abs($result_parse - $expected_parse) < $tolerance;
    
    // Test formatMontoLatino
    $format_ok = true;
    if ($expected_format !== null) {
        $result_format = formatMontoLatino($result_parse);
        $format_ok = ($result_format === $expected_format);
    }
    
    $status = ($parse_ok && $format_ok) ? 'success' : 'error';
    $symbol = $status === 'success' ? '✓' : '✗';
    
    echo sprintf(
        "Test #%02d: %s %s\n",
        $test_number,
        colorize($symbol, $status),
        $description
    );
    echo sprintf(
        "  Input:    %s\n",
        var_export($input, true)
    );
    echo sprintf(
        "  Parse:    %s (expected: %s) %s\n",
        $result_parse,
        $expected_parse,
        $parse_ok ? colorize('[OK]', 'success') : colorize('[FAIL]', 'error')
    );
    
    if ($expected_format !== null) {
        echo sprintf(
            "  Format:   %s (expected: %s) %s\n",
            $result_format,
            $expected_format,
            $format_ok ? colorize('[OK]', 'success') : colorize('[FAIL]', 'error')
        );
    }
    
    echo "\n";
    
    return ($parse_ok && $format_ok);
}

echo colorize("=" . str_repeat("=", 70) . "\n", 'info');
echo colorize("  TEST SUITE: Sistema de Montos LATAM (parseMontoLatino & formatMontoLatino)\n", 'info');
echo colorize("=" . str_repeat("=", 70) . "\n", 'info');
echo "\n";

$passed = 0;
$total = 0;

// ===== TESTS DE PARSEO =====
echo colorize("--- TESTS DE PARSEO (parseMontoLatino) ---\n\n", 'info');

// Test 1: Formato estándar con punto decimal
$total++;
if (test('Formato estándar: "1234567.89"', '1234567.89', 1234567.89, '1.234.567,89')) $passed++;

// Test 2: Formato latino con coma decimal
$total++;
if (test('Formato latino: "1.234.567,89"', '1.234.567,89', 1234567.89, '1.234.567,89')) $passed++;

// Test 3: Decimal pequeño con punto
$total++;
if (test('Decimal pequeño punto: "0.5"', '0.5', 0.5, '0,50')) $passed++;

// Test 4: Decimal pequeño con coma
$total++;
if (test('Decimal pequeño coma: "0,5"', '0,5', 0.5, '0,50')) $passed++;

// Test 5: Solo punto decimal sin decimales
$total++;
if (test('Solo entero con punto: "1."', '1.', 1.0, '1,00')) $passed++;

// Test 6: Número con coma decimal sin miles
$total++;
if (test('Coma decimal sin miles: "652485,20"', '652485,20', 652485.20, '652.485,20')) $passed++;

// Test 7: Número entero sin decimales
$total++;
if (test('Entero sin decimales: "1000"', '1000', 1000.0, '1.000,00')) $passed++;

// Test 8: Cero
$total++;
if (test('Cero: "0"', '0', 0.0, '0,00')) $passed++;

// Test 9: String vacío
$total++;
if (test('String vacío: ""', '', 0.0, '0,00')) $passed++;

// Test 10: Número muy grande
$total++;
if (test('Número grande: "9999999.99"', '9999999.99', 9999999.99, '9.999.999,99')) $passed++;

// Test 11: Formato latino grande
$total++;
if (test('Latino grande: "9.999.999,99"', '9.999.999,99', 9999999.99, '9.999.999,99')) $passed++;

// Test 12: Solo decimales con punto
$total++;
if (test('Solo decimales punto: ".99"', '.99', 0.99, '0,99')) $passed++;

// Test 13: Solo decimales con coma
$total++;
if (test('Solo decimales coma: ",99"', ',99', 0.99, '0,99')) $passed++;

// Test 14: Número negativo con punto
$total++;
if (test('Negativo punto: "-1234.56"', '-1234.56', -1234.56, '-1.234,56')) $passed++;

// Test 15: Número negativo latino
$total++;
if (test('Negativo latino: "-1.234,56"', '-1.234,56', -1234.56, '-1.234,56')) $passed++;

// ===== TESTS DE ALIAS =====
echo colorize("--- TESTS DE ALIAS (parseAmount vs parseMontoLatino) ---\n\n", 'info');

// Test 16: Verificar que parseAmount y parseMontoLatino dan el mismo resultado
$total++;
$input = '1.234.567,89';
$result_parseAmount = parseAmount($input);
$result_parseMontoLatino = parseMontoLatino($input);
$alias_ok = ($result_parseAmount === $result_parseMontoLatino);
echo sprintf(
    "Test #%02d: %s Alias parseAmount === parseMontoLatino\n",
    16,
    $alias_ok ? colorize('✓', 'success') : colorize('✗', 'error')
);
echo sprintf(
    "  parseAmount('%s') = %s\n",
    $input,
    $result_parseAmount
);
echo sprintf(
    "  parseMontoLatino('%s') = %s\n",
    $input,
    $result_parseMontoLatino
);
echo sprintf(
    "  Result: %s\n",
    $alias_ok ? colorize('[OK]', 'success') : colorize('[FAIL]', 'error')
);
echo "\n";
if ($alias_ok) $passed++;

// ===== TESTS DE FORMATEO =====
echo colorize("--- TESTS DE FORMATEO (formatMontoLatino) ---\n\n", 'info');

// Test 17: Formatear con diferentes decimales
$total++;
$value = 1234567.89123;
$result_2 = formatMontoLatino($value, 2);
$result_4 = formatMontoLatino($value, 4);
$format_decimals_ok = ($result_2 === '1.234.567,89' && $result_4 === '1.234.567,8912');
echo sprintf(
    "Test #%02d: %s Formateo con diferentes decimales\n",
    17,
    $format_decimals_ok ? colorize('✓', 'success') : colorize('✗', 'error')
);
echo sprintf(
    "  formatMontoLatino(%s, 2) = %s (expected: 1.234.567,89) %s\n",
    $value,
    $result_2,
    $result_2 === '1.234.567,89' ? colorize('[OK]', 'success') : colorize('[FAIL]', 'error')
);
echo sprintf(
    "  formatMontoLatino(%s, 4) = %s (expected: 1.234.567,8912) %s\n",
    $value,
    $result_4,
    $result_4 === '1.234.567,8912' ? colorize('[OK]', 'success') : colorize('[FAIL]', 'error')
);
echo "\n";
if ($format_decimals_ok) $passed++;

// Test 18: Verificar que formatAmount y formatMontoLatino dan el mismo resultado
$total++;
$value = 652485.20;
$result_formatAmount = formatAmount($value);
$result_formatMontoLatino = formatMontoLatino($value);
$format_alias_ok = ($result_formatAmount === $result_formatMontoLatino);
echo sprintf(
    "Test #%02d: %s Alias formatAmount === formatMontoLatino\n",
    18,
    $format_alias_ok ? colorize('✓', 'success') : colorize('✗', 'error')
);
echo sprintf(
    "  formatAmount(%s) = %s\n",
    $value,
    $result_formatAmount
);
echo sprintf(
    "  formatMontoLatino(%s) = %s\n",
    $value,
    $result_formatMontoLatino
);
echo sprintf(
    "  Result: %s\n",
    $format_alias_ok ? colorize('[OK]', 'success') : colorize('[FAIL]', 'error')
);
echo "\n";
if ($format_alias_ok) $passed++;

// ===== TESTS DE CASOS ESPECIALES =====
echo colorize("--- TESTS DE CASOS ESPECIALES ---\n\n", 'info');

// Test 19: Múltiples puntos (usar el último como decimal)
$total++;
if (test('Múltiples puntos: "1.2.3.4.5"', '1.2.3.4.5', 1234.5, '1.234,50')) $passed++;

// Test 20: Valor ya en formato float
$total++;
if (test('Float directo: 1234.56', 1234.56, 1234.56, '1.234,56')) $passed++;

// Test 21: Espacios y símbolos
$total++;
if (test('Con espacios: " 1234.56 "', ' 1234.56 ', 1234.56, '1.234,56')) $passed++;

// ===== RESUMEN =====
echo colorize("=" . str_repeat("=", 70) . "\n", 'info');
echo sprintf(
    "  RESUMEN: %s de %s tests pasaron\n",
    colorize($passed, $passed === $total ? 'success' : 'error'),
    $total
);
echo colorize("=" . str_repeat("=", 70) . "\n", 'info');

if ($passed === $total) {
    echo colorize("\n✅ TODOS LOS TESTS PASARON EXITOSAMENTE\n\n", 'success');
    exit(0);
} else {
    echo colorize(sprintf("\n❌ %d TESTS FALLARON\n\n", $total - $passed), 'error');
    exit(1);
}
