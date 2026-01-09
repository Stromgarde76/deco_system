/**
 * Utilidad para manejar campos de input de montos
 * 
 * Reglas:
 * - El usuario ingresa usando PUNTO como separador decimal (ej: 652485.20)
 * - No se permiten separadores de miles durante la entrada
 * - Al mostrar, se formatea en estilo latino: miles con punto, decimal con coma (ej: 652.485,20)
 * - Al enviar al servidor, se normaliza a formato estándar (ej: 652485.20)
 */

/**
 * Formatea un número para mostrarlo en formato latino (123.456,78)
 * @param {number} value - Valor numérico
 * @param {number} decimals - Cantidad de decimales (default: 2)
 * @returns {string} - Número formateado
 */
function formatAmountDisplay(value, decimals = 2) {
    if (value === null || value === undefined || value === '' || isNaN(Number(value))) {
        return '';
    }
    const num = Number(value);
    
    // Formatear con separador de miles (.) y decimal (,)
    const parts = num.toFixed(decimals).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return parts.join(',');
}

/**
 * Parsea el input del usuario y lo normaliza a formato estándar (sin separadores de miles, punto decimal)
 * @param {string} str - String ingresado por el usuario
 * @returns {string} - String normalizado (ej: "652485.20")
 */
function parseAmountInput(str) {
    if (!str || str.trim() === '') return '';
    
    // Limpiar el string
    str = String(str).trim();
    
    // Remover todo excepto dígitos, punto y guión (para negativos)
    str = str.replace(/[^\d.\-]/g, '');
    
    // Manejar múltiples puntos (mantener solo el último como decimal)
    const dotCount = (str.match(/\./g) || []).length;
    if (dotCount > 1) {
        // Tomar el último punto como decimal
        const lastDotIndex = str.lastIndexOf('.');
        str = str.substring(0, lastDotIndex).replace(/\./g, '') + str.substring(lastDotIndex);
    }
    
    // Si hay punto, limitar decimales a 2
    if (str.includes('.')) {
        const parts = str.split('.');
        if (parts[1] && parts[1].length > 2) {
            str = parts[0] + '.' + parts[1].substring(0, 2);
        }
    }
    
    return str;
}

/**
 * Maneja el evento onblur en un campo de input de monto
 * Formatea el valor para mostrarlo en formato latino
 * @param {HTMLInputElement} input - Elemento input
 * @param {boolean} skipIfFormatted - Si es true, no reformatea valores ya formateados
 */
function handleAmountBlur(input, skipIfFormatted = false) {
    if (!input) return;
    
    const value = input.value.trim();
    
    // Si skipIfFormatted es true y el valor ya está en formato latino, no hacer nada
    // Formato latino tiene coma como decimal, así que si tiene coma asumimos que ya está formateado
    if (skipIfFormatted && value.includes(',')) {
        return;
    }
    
    const normalized = parseAmountInput(value);
    if (normalized !== '') {
        const num = parseFloat(normalized);
        if (!isNaN(num)) {
            input.value = formatAmountDisplay(num);
        }
    }
}

/**
 * Maneja el evento onfocus en un campo de input de monto
 * Desnormaliza el valor para permitir edición con punto como decimal
 * @param {HTMLInputElement} input - Elemento input
 */
function handleAmountFocus(input) {
    if (!input) return;
    
    // Si el valor está en formato latino (con puntos de miles y coma decimal),
    // convertirlo a formato con punto decimal para edición
    let value = input.value.trim();
    if (value === '') return;
    
    // Remover puntos de miles
    value = value.replace(/\./g, '');
    // Reemplazar coma decimal por punto
    value = value.replace(',', '.');
    
    input.value = value;
}

/**
 * Prepara un formulario antes de enviarlo
 * Normaliza todos los campos de monto al formato estándar
 * @param {HTMLFormElement} form - Elemento form
 * @param {string[]} amountFields - Array con los nombres de los campos de monto
 */
function prepareAmountForm(form, amountFields) {
    if (!form || !amountFields) return true;
    
    amountFields.forEach(fieldName => {
        const input = form.querySelector(`[name="${fieldName}"]`);
        if (input && input.value) {
            input.value = parseAmountInput(input.value);
        }
    });
    
    return true;
}

/**
 * Mueve el foco al siguiente campo enfocable en el formulario
 * @param {HTMLInputElement} currentInput - Elemento input actual
 */
function moveToNextField(currentInput) {
    if (!currentInput || !currentInput.form) return;
    
    const form = currentInput.form;
    const formElements = Array.from(form.elements);
    const currentIndex = formElements.indexOf(currentInput);
    
    // Buscar el siguiente elemento enfocable
    for (let i = currentIndex + 1; i < formElements.length; i++) {
        const element = formElements[i];
        // Verificar si el elemento es enfocable (input, select, textarea, button)
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
 * Inicializa un campo de input para montos
 * @param {string|HTMLInputElement} selector - Selector CSS o elemento input
 */
function initAmountInput(selector) {
    const input = typeof selector === 'string' ? document.querySelector(selector) : selector;
    if (!input) return;
    
    input.addEventListener('focus', function() {
        handleAmountFocus(this);
    });
    
    input.addEventListener('blur', function() {
        handleAmountBlur(this, true); // Skip if already formatted
    });
    
    // Validar input: solo dígitos y un punto decimal
    input.addEventListener('input', function(e) {
        let value = this.value;
        
        // Verificar si comienza con signo negativo
        const isNegative = value.startsWith('-');
        
        // Permitir solo dígitos y punto (el guión se maneja por separado)
        value = value.replace(/[^\d.]/g, '');
        
        // Restaurar signo negativo al inicio si estaba presente
        if (isNegative) {
            value = '-' + value;
        }
        
        // Prevenir múltiples puntos: mantener solo el último punto como decimal
        const lastDotIndex = value.lastIndexOf('.');
        if (lastDotIndex !== -1) {
            const beforeLastDot = value.substring(0, lastDotIndex).replace(/\./g, '');
            const afterLastDot = value.substring(lastDotIndex + 1).replace(/\./g, '');
            value = beforeLastDot + '.' + afterLastDot;
            
            // Si hay signo negativo, asegurar que esté al inicio
            if (value.includes('-')) {
                value = '-' + value.replace(/-/g, '');
            }
        }
        
        // Limitar decimales a 2 dígitos después del punto
        const dotIndex = value.lastIndexOf('.');
        if (dotIndex !== -1) {
            const decimals = value.substring(dotIndex + 1);
            if (decimals.length > 2) {
                value = value.substring(0, dotIndex + 1) + decimals.substring(0, 2);
            }
        }
        
        // Solo actualizar si cambió
        if (this.value !== value) {
            this.value = value;
        }
    });
    
    // Manejar Enter para formatear y mover al siguiente campo
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            // Formatear el campo actual (forzar formato incluso si ya está formateado)
            handleAmountBlur(this, false);
            // Mover al siguiente campo enfocable
            moveToNextField(this);
        }
    });
}

/**
 * Inicializa múltiples campos de input para montos
 * @param {string} selector - Selector CSS para múltiples inputs
 */
function initAmountInputs(selector = '.amount-input') {
    const inputs = document.querySelectorAll(selector);
    inputs.forEach(input => initAmountInput(input));
}

// Auto-inicializar campos con clase 'amount-input' cuando el DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initAmountInputs());
} else {
    initAmountInputs();
}
