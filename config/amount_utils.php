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
    
    // Detectar formato basándose en el uso de coma y punto
    // Formato latino: usa coma como decimal (1.234.567,89)
    // Formato estándar: usa punto como decimal (1234567.89 o 1,234,567.89)
    
    $hasComma = strpos($str, ',') !== false;
    $hasDot = strpos($str, '.') !== false;
    
    if ($hasComma && $hasDot) {
        // Ambos presentes: determinar cuál es el decimal por posición
        $lastCommaPos = strrpos($str, ',');
        $lastDotPos = strrpos($str, '.');
        
        if ($lastCommaPos > $lastDotPos) {
            // Formato latino: 1.234.567,89 (coma es decimal)
            $str = str_replace('.', '', $str); // Remover separadores de miles
            $str = str_replace(',', '.', $str); // Convertir separador decimal a punto
        } else {
            // Formato estándar: 1,234,567.89 (punto es decimal)
            $str = str_replace(',', '', $str); // Remover separadores de miles
        }
    } elseif ($hasComma) {
        // Solo coma: es formato latino (1.234,56 o 0,50)
        $str = str_replace('.', '', $str); // Remover separadores de miles
        $str = str_replace(',', '.', $str); // Convertir separador decimal a punto
    }
    // Si solo tiene punto o ninguno, ya está en formato estándar
    
    // Remover todo excepto dígitos, punto y guión
    $str = preg_replace('/[^\d.\-]/', '', $str);
    
    // Asegurar solo un punto decimal (remover extras)
    $parts = explode('.', $str);
    if (count($parts) > 2) {
        // Mantener primera parte y solo primera parte decimal
        $str = $parts[0] . '.' . $parts[1];
    }
    
    // Asegurar solo un guión al inicio (para negativos)
    if (substr_count($str, '-') > 0) {
        $str = str_replace('-', '', $str);
        $str = '-' . $str;
    }
    
    // Convertir a float
    return floatval($str);
}

/**
 * Alias de parseAmount() - nombre solicitado en especificación del problema
 * Este alias existe específicamente porque fue requerido en la especificación:
 * "asegurar parseo robusto desde '1.234.567,89' a float 1234567.89 (helper parseMontoLatino)"
 * 
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
