// File: assets/js/flujocaja.js
// Formateo: miles = '.' y decimal = ',' para todas las monedas (Bs y $).
// Formatea en blur y al presionar Enter.
// Exporta fcj_formatBs, fcj_formatUsd, fcj_unformat

(function(window, document){
    'use strict';

    // Formatea número a "1.234.567,89"
    function fcj_formatBs(num){
        if (num === null || num === undefined || isNaN(Number(num))) return '';
        var parts = Number(num).toFixed(2).split('.');
        var intPart = parts[0];
        var dec = parts[1];
        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return intPart + ',' + dec;
    }

    // Usamos el mismo formato para USD (tal como pediste: miles='.' decimal=',')
    function fcj_formatUsd(num){
        return fcj_formatBs(num);
    }

    /**
     * Normaliza una cadena numérica a formato con punto decimal (ej. "1582.50" o "1.582,50" -> "1582.50")
     * Devuelve cadena tipo "1234.56" (lista para parseFloat o envío al servidor).
     *
     * Reglas:
     * - Si contiene coma y punto: se determina cuál es el separador decimal por la última aparición.
     *   - Si la última coma está después del último punto => coma es decimal: quitar puntos (miles) y convertir coma->dot.
     *   - Si la última punto está después de la última coma => punto es decimal: quitar comas (miles) y mantener punto.
     * - Si contiene solo comas => tratar coma como decimal (',' -> '.').
     * - Si contiene solo puntos:
     *   - Si hay más de un punto: asumir último punto como decimal y los anteriores como separadores de miles.
     *   - Si hay un solo punto: tratar ese punto como decimal.
     * - También acepta entradas como "1582.50" (pad numérico) y las normaliza correctamente.
     */
    function fcj_unformat(str){
        if (str === null || str === undefined) return '';
        str = String(str).trim();
        if (str === '') return '';

        // eliminar NBSP y espacios
        str = str.replace(/\u00A0/g,'').replace(/\s/g,'');

        // eliminar cualquier caracter que no sea dígito, coma, punto o signo menos
        str = str.replace(/[^\d\-\.,]/g,'');

        var hasComma = str.indexOf(',') !== -1;
        var hasDot = str.indexOf('.') !== -1;

        if (hasComma && hasDot) {
            var lastComma = str.lastIndexOf(',');
            var lastDot = str.lastIndexOf('.');
            if (lastComma > lastDot) {
                // coma es decimal -> quitar puntos miles, cambiar coma por punto decimal
                str = str.replace(/\./g, '');
                str = str.replace(/,/g, '.');
            } else {
                // punto es decimal -> quitar comas miles
                str = str.replace(/,/g, '');
                // punto queda como decimal
            }
        } else if (hasComma && !hasDot) {
            // solo coma: tratar coma como decimal
            str = str.replace(/\./g, '');
            str = str.replace(/,/g, '.');
        } else if (!hasComma && hasDot) {
            // solo puntos: puede haber varios (ej. "1.582.50" o "1582.50")
            var parts = str.split('.');
            if (parts.length > 2) {
                // asumir ultimo punto como decimal, los anteriores como miles
                var last = parts.pop();
                str = parts.join('') + '.' + last;
            } else {
                // un solo punto => lo dejamos como decimal
                // str stays as-is
            }
        } else {
            // solo dígitos (sin separadores) -> queda igual
        }

        // eliminar todo excepto dígitos, punto y signo menos
        str = str.replace(/[^0-9\.\-]/g, '');

        // asegurar que solo quede un punto decimal (si hay >1, mantener el último)
        var partsFinal = str.split('.');
        if (partsFinal.length > 2) {
            var last = partsFinal.pop();
            str = partsFinal.join('') + '.' + last;
        }

        return str;
    }

    // Asocia comportamiento de formateo al input: blur y Enter
    function attachFormatOnBlurAndEnter(input, tipoGetFn){
        if (!input) return;

        input.addEventListener('blur', function(){
            var tipo = (typeof tipoGetFn === 'function') ? tipoGetFn() : 'Bs';
            var raw = fcj_unformat(this.value);
            var n = parseFloat(raw);
            if (isNaN(n)) { this.value = ''; return; }
            if (tipo === 'Bs') this.value = fcj_formatBs(n);
            else this.value = fcj_formatUsd(n);
        });

        input.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                var tipo = (typeof tipoGetFn === 'function') ? tipoGetFn() : 'Bs';
                var raw = fcj_unformat(this.value);
                var n = parseFloat(raw);
                if (!isNaN(n)) {
                    if (tipo === 'Bs') this.value = fcj_formatBs(n);
                    else this.value = fcj_formatUsd(n);
                }
                // Si está dentro de un form, pasar foco al siguiente elemento para evitar submit inmediato
                var form = this.form;
                if (form) {
                    var elements = Array.from(form.elements).filter(function(el){
                        return (el.tagName.toLowerCase() !== 'button' && el.type !== 'hidden' && !el.disabled);
                    });
                    var idx = elements.indexOf(this);
                    if (idx >= 0 && idx < elements.length-1) {
                        elements[idx+1].focus();
                    }
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function(){
        var monto = document.getElementById('monto_input');
        var monto_edit = document.getElementById('monto_input_edit');

        function tipoActual(){
            var instr = document.getElementById('instrumento');
            return instr ? instr.value : 'Bs';
        }
        function tipoEdit(){
            var instr = document.getElementById('instrumento_edit');
            return instr ? instr.value : 'Bs';
        }

        if (monto) attachFormatOnBlurAndEnter(monto, tipoActual);
        if (monto_edit) attachFormatOnBlurAndEnter(monto_edit, tipoEdit);

        // mantener la etiqueta de moneda sincronizada y forzar formateo al cambiar instrumento
        var instr = document.getElementById('instrumento');
        if (instr){
            instr.addEventListener('change', function(){
                var label = document.getElementById('currency_label');
                if (label) label.innerText = instr.value === 'Bs' ? 'Bs' : '$';
                if (monto) monto.dispatchEvent(new Event('blur'));
            });
        }
        var instr_edit = document.getElementById('instrumento_edit');
        if (instr_edit){
            instr_edit.addEventListener('change', function(){
                var label = document.getElementById('currency_label_edit');
                if (label) label.innerText = instr_edit.value === 'Bs' ? 'Bs' : '$';
                if (monto_edit) monto_edit.dispatchEvent(new Event('blur'));
            });
        }
    });

    // Exportar funciones globales (si otros scripts las usan)
    window.fcj_formatBs = fcj_formatBs;
    window.fcj_formatUsd = fcj_formatUsd;
    window.fcj_unformat = fcj_unformat;

})(window, document);