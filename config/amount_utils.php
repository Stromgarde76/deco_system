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
 * Acepta formato con punto como decimal (652485.20)
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
    
    // Remover todo excepto dígitos, punto y guión
    $str = preg_replace('/[^\d.\-]/', '', $str);
    
    // Convertir a float
    return floatval($str);
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
