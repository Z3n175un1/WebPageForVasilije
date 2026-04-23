/**
 * Cliente de logging de seguridad
 * Ubicación: /public/js/security.js
 */

class SecurityLogger {
    constructor() {
        this.apiUrl = '/api/user_log.php';
        this.sessionId = this.getSessionId();
        this.userId = document.body.getAttribute('data-user-id') || null;
        this.username = document.body.getAttribute('data-username') || null;
    }
    
    getSessionId() {
        // Intentar obtener de cookie
        const cookies = document.cookie.split(';');
        for (let cookie of cookies) {
            const [name, value] = cookie.trim().split('=');
            if (name === 'PHPSESSID' || name.includes('session')) {
                return value;
            }
        }
        return null;
    }
    
    async log(eventType, data = {}, riskLevel = 'bajo') {
        try {
            const payload = {
                tipo: eventType,
                datos: data,
                nivel_riesgo: riskLevel,
                url: window.location.href,
                username: this.username,
                timestamp: Date.now()
            };
            
            // Enviar usando fetch (beacon para no bloquear)
            if (navigator.sendBeacon) {
                const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
                navigator.sendBeacon(this.apiUrl, blob);
            } else {
                // Fallback a fetch
                fetch(this.apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(payload),
                    keepalive: true
                }).catch(err => console.warn('Error logging security event:', err));
            }
        } catch (e) {
            console.warn('Error logging security event:', e);
        }
    }
    
    // Eventos específicos
    logClick(element) {
        this.log('interaccion', {
            elemento: element.tagName,
            id: element.id,
            clase: element.className,
            texto: element.innerText?.substring(0, 50)
        }, 'bajo');
    }
    
    logFormSubmit(formId, formData) {
        this.log('form_submit', {
            formulario: formId,
            campos: Object.keys(formData).length
        }, 'bajo');
    }
    
    logError(error) {
        this.log('error_cliente', {
            mensaje: error.message,
            stack: error.stack?.substring(0, 200)
        }, 'medio');
    }
    
    logSuspiciousActivity(description, data = {}) {
        this.log('actividad_sospechosa', {
            descripcion: description,
            ...data
        }, 'alto');
    }
}

// Inicializar logger
const securityLogger = new SecurityLogger();

// Detectar clics sospechosos (rápidos, muchos)
let clickCount = 0;
let lastClickTime = 0;

document.addEventListener('click', function(e) {
    const now = Date.now();
    if (now - lastClickTime < 100) { // Menos de 100ms entre clics
        clickCount++;
        if (clickCount > 10) { // Más de 10 clics rápidos
            securityLogger.logSuspiciousActivity('Rapid clicking detected', {
                count: clickCount,
                target: e.target.tagName
            });
            clickCount = 0;
        }
    } else {
        clickCount = 0;
    }
    lastClickTime = now;
});

// Detectar copiado de contenido sensible
document.addEventListener('copy', function(e) {
    const selection = window.getSelection().toString();
    if (selection.length > 100) {
        securityLogger.log('copy_attempt', {
            length: selection.length,
            preview: selection.substring(0, 50)
        }, 'medio');
    }
});

// Exportar para uso global
window.securityLogger = securityLogger;