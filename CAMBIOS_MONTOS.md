# Actualización del Sistema de Input de Montos

## Resumen de Cambios

Este documento describe la actualización realizada en el sistema para estandarizar la entrada y visualización de montos monetarios.

## Objetivo

Permitir que los usuarios ingresen montos usando el **punto (.) como separador decimal únicamente** (como en el teclado numérico), sin separadores de miles durante la entrada. El sistema automáticamente formatea y muestra los montos en **formato latino** (punto para miles, coma para decimales).

## Comportamiento

### Entrada de Usuario
- Usuario escribe: `652485.20` (punto como decimal, sin separadores de miles)
- Usuario puede escribir también: `652485` (sin decimales) o `0.50` (decimales solamente)

### Visualización
- Sistema muestra: `652.485,20` (formato latino: punto para miles, coma para decimales)
- Todos los montos en tablas y listados se muestran con formato latino

### Almacenamiento
- Base de datos recibe: `652485.20` (formato estándar con punto decimal)
- Sin pérdida de precisión en la conversión

## Archivos Creados

### 1. `/assets/js/amount-input.js`
Utilidad JavaScript reutilizable que maneja:
- Formateo automático al perder foco (blur)
- Desformateo al obtener foco (focus) para edición
- Normalización antes de enviar al servidor
- Auto-inicialización de campos con clase `amount-input`

**Funciones principales:**
- `formatAmountDisplay(value)` - Formatea número a formato latino
- `parseAmountInput(str)` - Normaliza string a formato estándar
- `initAmountInput(selector)` - Inicializa un campo de input
- `handleAmountBlur(input)` - Maneja evento blur
- `handleAmountFocus(input)` - Maneja evento focus

### 2. `/config/amount_utils.php`
Utilidad PHP reutilizable que maneja:
- Parseo de montos del input del usuario
- Formateo de montos para visualización

**Funciones principales:**
- `parseAmount($input)` - Convierte input a float
- `formatAmount($amount, $decimals = 2)` - Formatea para display usando number_format

## Archivos Modificados

### Módulos de Formularios
Todos actualizados con el mismo patrón:

1. **pagos.php** - Formulario de pagos
   - Agregado `require_once "../config/amount_utils.php"`
   - Uso de `parseAmount()` en lugar de conversiones manuales
   - Uso de `formatAmount()` para display
   - Agregado `<script src="../assets/js/amount-input.js"></script>`
   - Input con clase `amount-input`

2. **cobranzas.php** - Formulario de cobranzas
   - Mismos cambios que pagos.php

3. **servicios.php** - Formulario de servicios
   - Cambio de `type="number"` a `type="text"` con clase `amount-input`
   - Campo de costo actualizado

4. **bancos.php** - Formulario de bancos
   - Cambio de `type="number"` a `type="text"` con clase `amount-input`
   - Campo de saldo actualizado

5. **trabajos.php** - Formulario de trabajos
   - Cambio de `type="number"` a `type="text"` con clase `amount-input`
   - Campo de monto_inicial actualizado
   - Display en tablas actualizado

6. **proyectos_editar.php** - Edición de proyectos
   - Reemplazo de función `formatMontoInput()` por utilidad estándar
   - Input actualizado con clase `amount-input`

7. **flujocaja.php** - Flujo de caja
   - Adaptación de lógica existente (tenía formato en-US)
   - Cambio a formato latino (es-ES)
   - Funciones `fcj_formatNumber()` y `fcj_unformat()` actualizadas
   - Integración con nueva utilidad `amount-input.js`

## Patrón de Implementación

### Backend (PHP)
```php
// Al inicio del archivo
require_once "../config/amount_utils.php";

// Al procesar POST
$monto = parseAmount($_POST['monto']);

// Al mostrar en HTML
echo formatAmount($valor);
// o directamente con number_format:
echo number_format($valor, 2, ',', '.');
```

### Frontend (HTML)
```html
<!-- En el <head> -->
<script src="../assets/js/amount-input.js"></script>

<!-- En el formulario -->
<input type="text" id="monto" name="monto" class="amount-input" 
       value="<?php echo formatAmount($monto); ?>" 
       placeholder="Monto">
```

### Frontend (JavaScript)
```javascript
// Inicialización manual (opcional, auto-inicializa con clase)
window.addEventListener('DOMContentLoaded', function() {
    initAmountInput('#monto');
});
```

## Casos de Uso Cubiertos

### Entrada Normal
- `1234.56` → muestra `1.234,56`
- `1000000` → muestra `1.000.000,00`
- `0.5` → muestra `0,50`

### Edición de Valores Existentes
- Al cargar: Base de datos `1234.56` → muestra `1.234,56`
- Al hacer clic: `1.234,56` → permite editar `1234.56`
- Al salir: `1234.56` → muestra `1.234,56`

### Validación
- Solo permite dígitos y un punto decimal
- Limita a 2 decimales automáticamente
- Maneja correctamente ceros y valores pequeños

## Testing

Se ha creado un archivo de prueba en `/test_amount_input.html` que permite verificar:
- Input básico con formateo
- Múltiples inputs simultáneos
- Casos especiales (decimales, ceros, valores grandes)
- Simulación de envío de formulario

Para probar:
1. Abrir `test_amount_input.html` en el navegador
2. Seguir las instrucciones en pantalla
3. Verificar que los valores se formatean correctamente

## Compatibilidad

- Compatible con todos los navegadores modernos
- Usa `Intl.NumberFormat` con fallback
- No requiere librerías externas
- Compatible con formularios existentes

## Notas Importantes

1. Los campos de **tasa de cambio** (`tasa_cambio`) se mantienen como `type="number"` ya que no son montos de usuario sino tasas decimales.

2. El archivo `flujocaja.php` tenía una implementación personalizada que se **adaptó** en lugar de reemplazar completamente, manteniendo su lógica de validación de saldos bancarios.

3. Los archivos `reportes.php` y `dashboard.php` no tienen inputs de montos, solo visualización, por lo que no requirieron cambios más allá del uso de `number_format` que ya tenían.

## Beneficios

- ✅ Consistencia en toda la aplicación
- ✅ Mejor experiencia de usuario (puede usar teclado numérico)
- ✅ Código reutilizable y mantenible
- ✅ Formato latino estándar en toda la interfaz
- ✅ Sin pérdida de precisión en conversiones
- ✅ Validación automática de formato

## Próximos Pasos (Opcional)

Para mejorar aún más el sistema, se podría considerar:
- Agregar tests automatizados
- Soporte para otros formatos regionales
- Integración con sistemas de internacionalización
- Validación adicional de rangos de valores
