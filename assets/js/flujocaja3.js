// Archivo: assets/js/flujocaja.js
// Formateo: ahora SOLO formatea en blur o al presionar Enter.
// exporta fcj_formatBs, fcj_formatUsd, fcj_unformat

(function(window, document){
    function fcj_formatBs(num){
        if (isNaN(num) || num === null) return '';
        var parts = Number(num).toFixed(2).split('.');
        var intPart = parts[0];
        var dec = parts[1];
        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
        return intPart + ',' + dec;
    }
    function fcj_formatUsd(num){
        if (isNaN(num) || num === null) return '';
        var parts = Number(num).toFixed(2).split('.');
        var intPart = parts[0];
        var dec = parts[1];
        intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        return intPart + '.' + dec;
    }

    function fcj_unformat(str, tipo){
        if (!str) return '';
        str = String(str).trim();
        str = str.replace(/[^\d\.,-]/g, '');
        if (tipo === 'Bs'){
            // quitar miles '.' y cambiar ',' -> '.'
            str = str.replace(/\./g, '');
            str = str.replace(/,/g, '.');
        } else {
            // USD: quitar miles ',' (coma)
            str = str.replace(/,/g, '');
        }
        return str;
    }

    function attachFormatOnBlurAndEnter(input, tipoGetFn){
        if (!input) return;

        // On blur: format
        input.addEventListener('blur', function(){
            var raw = fcj_unformat(this.value, tipoGetFn());
            var n = parseFloat(raw);
            if (isNaN(n)) { this.value = ''; return; }
            if (tipoGetFn() === 'Bs') this.value = fcj_formatBs(n);
            else this.value = fcj_formatUsd(n);
        });

        // On keydown Enter: format and prevent first form submit so user gets formatted value.
        input.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                var raw = fcj_unformat(this.value, tipoGetFn());
                var n = parseFloat(raw);
                if (!isNaN(n)) {
                    if (tipoGetFn() === 'Bs') this.value = fcj_formatBs(n);
                    else this.value = fcj_formatUsd(n);
                }
                // If inside a form, focus next form element (so Enter doesn't resubmit immediately)
                var form = this.form;
                if (form) {
                    var elements = Array.from(form.elements).filter(function(el){ return el.tagName.toLowerCase() !== 'button' && !el.disabled; });
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

        // Keep currency badge updated when instrument changes
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

    window.fcj_formatBs = fcj_formatBs;
    window.fcj_formatUsd = fcj_formatUsd;
    window.fcj_unformat = fcj_unformat;
})(window, document);