/**
 * Sistema centralizado para manejo de montos y cantidades - Formato LATAM
 * 
 * Reglas de comportamiento:
 * - Durante escritura: solo dígitos y UN punto (.) como separador decimal. No separadores de miles.
 * - Al presionar Enter: formatear a formato latino (1.234.567,89) y mover foco al siguiente campo.
 * - Backend recibe: formato latino que se parsea a float estándar.
 * - Display: formato latino consistente (miles con punto, decimales con coma).
 */

(function() {
    'use strict';

    /**
     * Formatea un número desde formato con punto decimal a formato latino
     * Ejemplo: "1234567.89" → "1.234.567,89"
     * Preserva TODOS los dígitos sin truncar
     * 
     * Esta función maneja valores al momento de formatear (Enter o blur),
     * donde puede recibir diversos formatos incluyendo valores ya formateados.
     * Usa el ÚLTIMO punto como decimal para manejar casos como "1.234.567.89"
     * 
     * @param {string} value - Valor con punto como decimal
     * @returns {string} - Valor formateado en formato latino
     */
    function formatLatamFromDot(value) {
        if (!value || value.trim() === '') return '';
        
        let str = value.trim();
        
        // Remover todo excepto dígitos, punto y guión
        str = str.replace(/[^\d.\-]/g, '');
        
        // Manejar signo negativo (solo permitir uno al inicio)
        const isNegative = str.startsWith('-');
        // Remover TODOS los guiones para limpiar y solo agregar uno al inicio si es necesario
        str = str.replace(/-/g, '');
        
        // Si hay múltiples puntos, usar el ÚLTIMO como separador decimal
        // Ejemplo: "1.2.3.4.5" se interpreta como entero "1234" + decimal ".5" = 1234.5
        // Esto maneja el caso donde el usuario escribe puntos accidentalmente o
        // cuando se recibe un valor ya formateado con separadores de miles (1.234.567)
        const dotCount = (str.match(/\./g) || []).length;
        if (dotCount > 1) {
            const lastDotIndex = str.lastIndexOf('.');
            // Juntar todas las partes antes del último punto (parte entera)
            const integerPart = str.substring(0, lastDotIndex).replace(/\./g, '');
            // Mantener todo después del último punto (parte decimal)
            const decimalPart = str.substring(lastDotIndex + 1);
            str = integerPart + '.' + decimalPart;
        }
        
        // Parsear a número
        const num = parseFloat(str);
        if (isNaN(num)) return '';
        
        // Formatear con separador de miles (.) y decimal (,)
        const parts = num.toFixed(2).split('.');
        
        // Aplicar separador de miles a la parte entera
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        
        // Unir con coma como separador decimal
        const result = parts.join(',');
        
        return isNegative ? '-' + result : result;
    }

    /**
     * Mueve el foco al siguiente campo enfocable del formulario
     * @param {HTMLInputElement} currentInput - Campo actual
     */
    function moveToNextField(currentInput) {
        if (!currentInput || !currentInput.form) return;
        
        const form = currentInput.form;
        const formElements = Array.from(form.elements);
        const currentIndex = formElements.indexOf(currentInput);
        
        // Buscar el siguiente elemento enfocable
        for (let i = currentIndex + 1; i < formElements.length; i++) {
            const element = formElements[i];
            // Verificar si el elemento es enfocable
            if (element.type !== 'hidden' && 
                !element.disabled && 
                !element.readOnly &&
                element.tabIndex !== -1 &&
                (element.tagName === 'INPUT' || 
                 element.tagName === 'SELECT' || 
                 element.tagName === 'TEXTAREA' ||
                 element.tagName === 'BUTTON')) {
                element.focus();
                return;
            }
        }
    }

    /**
     * Sanitiza el input para permitir solo dígitos y un solo punto
     * Esta función se usa durante la escritura en tiempo real (evento input)
     * Mantiene el PRIMER punto para permitir al usuario continuar escribiendo decimales
     * 
     * @param {string} value - Valor del input
     * @returns {string} - Valor sanitizado
     */
    function sanitizeInput(value) {
        if (!value) return '';
        
        // Permitir solo dígitos, punto y guión
        let sanitized = value.replace(/[^\d.\-]/g, '');
        
        // Manejar signo negativo (solo permitir uno al inicio)
        // Remover múltiples guiones (ej: "--123" o "12-3-4" se convierten en "1233" o "-1233")
        const isNegative = sanitized.startsWith('-');
        sanitized = sanitized.replace(/-/g, '');
        if (isNegative) {
            sanitized = '-' + sanitized;
        }
        
        // Durante la escritura: mantener solo el PRIMER punto como decimal
        // Esto permite al usuario escribir "123." y continuar con los decimales
        // Ejemplo: usuario escribe "123.4" → se mantiene "123.4"
        // Si intenta "123.4.5" → se sanitiza a "123.45" (quita el segundo punto)
        const firstDotIndex = sanitized.indexOf('.');
        if (firstDotIndex !== -1) {
            const beforeDot = sanitized.substring(0, firstDotIndex + 1);
            const afterDot = sanitized.substring(firstDotIndex + 1).replace(/\./g, '');
            sanitized = beforeDot + afterDot;
        }
        
        return sanitized;
    }

    /**
     * Inicializa un campo de input para manejo de montos
     * @param {HTMLInputElement} input - Elemento input a inicializar
     */
    function initAmountInput(input) {
        if (!input || input.dataset.latamInitialized) return;
        
        // Marcar como inicializado para evitar duplicados
        input.dataset.latamInitialized = 'true';
        
        // Si es type=number, convertir a type=text para mayor control
        if (input.type === 'number') {
            input.type = 'text';
        }
        
        // Establecer inputmode para teclado numérico con decimal
        input.setAttribute('inputmode', 'decimal');
        input.setAttribute('autocomplete', 'off');
        
        // Event listener para input: sanitizar en tiempo real
        input.addEventListener('input', function(e) {
            const cursorPosition = this.selectionStart;
            const oldValue = this.value;
            const newValue = sanitizeInput(oldValue);
            
            if (oldValue !== newValue) {
                this.value = newValue;
                // Intentar mantener la posición del cursor
                const diff = oldValue.length - newValue.length;
                this.setSelectionRange(cursorPosition - diff, cursorPosition - diff);
            }
        });
        
        // Event listener para keydown: manejar teclas especiales
        input.addEventListener('keydown', function(e) {
            // Manejar NumpadDecimal (punto del teclado numérico)
            if (e.code === 'NumpadDecimal' || e.key === 'Decimal') {
                e.preventDefault();
                // Insertar un punto manualmente si no existe ya
                if (!this.value.includes('.')) {
                    const cursorPosition = this.selectionStart;
                    const value = this.value;
                    this.value = value.substring(0, cursorPosition) + '.' + value.substring(cursorPosition);
                    this.setSelectionRange(cursorPosition + 1, cursorPosition + 1);
                }
                return;
            }
            
            // Bloquear la coma durante la escritura
            if (e.key === ',') {
                e.preventDefault();
                return;
            }
            
            // Manejar Enter: formatear y avanzar
            if (e.key === 'Enter') {
                e.preventDefault();
                
                // Formatear el valor actual
                const formatted = formatLatamFromDot(this.value);
                if (formatted !== '') {
                    this.value = formatted;
                }
                
                // Mover al siguiente campo
                moveToNextField(this);
                return;
            }
        });
        
        // Event listener para focus: convertir de formato latino a formato editable
        input.addEventListener('focus', function() {
            let value = this.value.trim();
            if (value === '') return;
            
            // Si está en formato latino (con coma), convertir a punto
            if (value.includes(',')) {
                // Remover puntos de miles
                value = value.replace(/\./g, '');
                // Reemplazar coma decimal por punto
                value = value.replace(',', '.');
                this.value = value;
            }
        });
        
        // Event listener para blur: formatear a latino si tiene valor
        input.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value !== '' && !value.includes(',')) {
                // Solo formatear si no está ya en formato latino
                const formatted = formatLatamFromDot(value);
                if (formatted !== '') {
                    this.value = formatted;
                }
            }
        });
    }

    /**
     * Busca e inicializa todos los campos de monto/cantidad en el documento
     * Usa selectores amplios para detectar automáticamente los campos relevantes
     */
    function initializeAllAmountInputs() {
        // Selectores amplios para detectar campos de monto/cantidad
        const selectors = [
            'input[data-amount]',
            'input[data-monto]',
            'input[data-cantidad]',
            'input[name*="monto" i]',
            'input[name*="cantidad" i]',
            'input[name*="precio" i]',
            'input[name*="importe" i]',
            'input[name*="total" i]',
            'input[name*="valor" i]',
            'input.amount-input',
            'input[id*="monto" i]',
            'input[id*="cantidad" i]',
            'input[id*="precio" i]'
        ];
        
        const selector = selectors.join(', ');
        const inputs = document.querySelectorAll(selector);
        
        inputs.forEach(input => {
            // Excluir campos que explícitamente no son montos (ej: tasa_cambio)
            const name = input.name || '';
            const id = input.id || '';
            if (name.match(/tasa/i) || id.match(/tasa/i)) {
                return; // No aplicar a tasas de cambio
            }
            
            initAmountInput(input);
        });
    }

    /**
     * Exponer funciones globalmente para uso manual si es necesario
     */
    window.formatLatamFromDot = formatLatamFromDot;
    window.initAmountInput = initAmountInput;
    window.initializeAllAmountInputs = initializeAllAmountInputs;

    /**
     * Auto-inicializar cuando el DOM esté listo
     */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAllAmountInputs);
    } else {
        // El DOM ya está listo, inicializar inmediatamente
        initializeAllAmountInputs();
    }

    /**
     * También inicializar en nuevos elementos agregados dinámicamente
     * (usando MutationObserver para detectar cambios en el DOM)
     */
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes.length) {
                    initializeAllAmountInputs();
                }
            });
        });
        
        // Observar cambios en el body
        if (document.body) {
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        } else {
            document.addEventListener('DOMContentLoaded', function() {
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            });
        }
    }
})();
