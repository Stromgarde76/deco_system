<?php
/**
 * Utilidades para manejo de montos en el sistema
 * 
 * Reglas:
 * - El usuario ingresa usando PUNTO como separador decimal (ej: 652485.20)
 * - El servidor recibe el valor normalizado con punto como decimal
 * - Al mostrar, se usa number_format para formato latino: miles con punto, decimal con coma
 */

/**
 * Parsea un monto del input del usuario
 * Acepta AMBOS formatos:
 * - Formato con punto como decimal: 652485.20 o 1234567.89
 * - Formato latino (formateado): 652.485,20 o 1.234.567,89
 * Retorna float
 * 
 * @param string|float $input Valor del input
 * @return float Valor parseado
 */
function parseAmount($input) {
    // Si ya es número, retornar directamente
    if (is_numeric($input)) {
        return floatval($input);
    }
    
    // Convertir a string y limpiar
    $str = trim((string)$input);
    
    // Si está vacío, retornar 0
    if ($str === '') {
        return 0.0;
    }
    
    // Detectar si está en formato latino (tiene coma como decimal)
    // Formato latino: 1.234.567,89 (puntos para miles, coma para decimal)
    // Formato estándar: 1234567.89 (punto para decimal, sin separador de miles)
    if (strpos($str, ',') !== false) {
        // Es formato latino: remover puntos (miles) y convertir coma (decimal) a punto
        $str = str_replace('.', '', $str); // Remover separadores de miles
        $str = str_replace(',', '.', $str); // Convertir separador decimal a punto
    }
    
    // Remover todo excepto dígitos, punto y guión
    $str = preg_replace('/[^\d.\-]/', '', $str);
    
    // Convertir a float
    return floatval($str);
}

/**
 * Alias de parseAmount() - nombre solicitado en especificación
 * Parsea un monto en formato latino (1.234.567,89) o estándar (1234567.89)
 * 
 * @param string|float $input Valor del input
 * @return float Valor parseado
 */
function parseMontoLatino($input) {
    return parseAmount($input);
}

/**
 * Formatea un monto para mostrarlo en formato latino
 * 
 * @param float $amount Monto a formatear
 * @param int $decimals Cantidad de decimales (default: 2)
 * @return string Monto formateado (ej: 652.485,20)
 */
function formatAmount($amount, $decimals = 2) {
    return number_format($amount, $decimals, ',', '.');
}
