// Archivo: assets/js/flujocaja.js
// Helper para formateo automático de montos y parseo antes de enviar
// Incluye: fcj_formatBs, fcj_formatUsd, fcj_unformat

(function(window, document){
    // Formatea número a formato Bs: miles '.' y decimales ','
    function fcj_formatBs(num){
        if (isNaN(num) || num === null) return '';
        var parts = Number(num).toFixed(2).split('.');
        var intPart = parts[0];
        var dec = parts[1];
        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return intPart + ',' + dec;
    }
    // Formatea número a formato USD: miles ',' y decimales '.'
    function fcj_formatUsd(num){
        if (isNaN(num) || num === null) return '';
        var parts = Number(num).toFixed(2).split('.');
        var intPart = parts[0];
        var dec = parts[1];
        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        return intPart + '.' + dec;
    }

    // Quitar formato visual y devolver número en formato 'crudo' (con punto decimal)
    // tipo: 'Bs' o '$'
    function fcj_unformat(str, tipo){
        if (!str) return '';
        str = String(str).trim();
        // quitar moneda y espacios
        str = str.replace(/[^\d\.,-]/g, '');
        if (tipo === 'Bs'){
            // quitar miles '.' y cambiar ',' -> '.'
            str = str.replace(/\./g, '');
            str = str.replace(/,/g, '.');
        } else {
            // USD: quitar miles ',' (coma) y punto decimal queda como '.'
            str = str.replace(/,/g, '');
        }
        return str;
    }

    // Auto-format mientras escribe (attach to inputs with id monto_input, monto_input_edit)
    function attachAutoFormat(input, tipoGetFn){
        if (!input) return;
        input.addEventListener('input', function(e){
            var raw = fcj_unformat(this.value, tipoGetFn());
            // evitar NaN -> usar 0
            var n = parseFloat(raw);
            if (isNaN(n)) {
                // dejar contenido parcial
                return;
            }
            // formatear según tipo
            var tipo = tipoGetFn();
            if (tipo === 'Bs') this.value = fcj_formatBs(n);
            else this.value = fcj_formatUsd(n);
        });
        // en blur mantener formato final
        input.addEventListener('blur', function(){
            var raw = fcj_unformat(this.value, tipoGetFn());
            var n = parseFloat(raw);
            if (isNaN(n)) { this.value = ''; return; }
            var tipo = tipoGetFn();
            if (tipo === 'Bs') this.value = fcj_formatBs(n);
            else this.value = fcj_formatUsd(n);
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

        if (monto) attachAutoFormat(monto, tipoActual);
        if (monto_edit) attachAutoFormat(monto_edit, tipoEdit);
        // permitir que el formato se actualice cuando cambie el instrumento
        var instr = document.getElementById('instrumento');
        if (instr) instr.addEventListener('change', function(){ if (monto) monto.dispatchEvent(new Event('blur')); });
        var ins_edit = document.getElementById('instrumento_edit');
        if (ins_edit) ins_edit.addEventListener('change', function(){ if (monto_edit) monto_edit.dispatchEvent(new Event('blur')); });
    });

    // exportar funciones útiles al scope global
    window.fcj_formatBs = fcj_formatBs;
    window.fcj_formatUsd = fcj_formatUsd;
    window.fcj_unformat = fcj_unformat;
})(window, document);