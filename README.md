Lista de Espera – Integración MySQL + Oracle

Aplicación web en PHP que muestra en tiempo real la lista de pacientes en espera, combinando datos del sistema NemoQ (MySQL) con información del sistema SFere (Oracle).

 Funcionalidad

Esta aplicación:

 1. Consulta MySQL (NemoQ)

Obtiene información de pacientes y citas:

Ticket

Nombre y apellidos

Área de espera

Estado OGS

Punto de impresión (kiosco / mostrador / integración)

Tiempo de espera en minutos (calculado dinámicamente)

Filtrado por centros 4 y 6

Ordenado por mayor tiempo de espera

 2. Consulta Oracle (SFere)

Por cada resultado de NemoQ:

Obtiene el estado clínico del acto (PROGRAMADO, ADMITIDO, REALIZADO, etc.)

Filtra y solo muestra los actos PROGRAMADOS

 3. Interfaz Web

Renderiza una tabla con:

Datos del ticket

Datos del paciente

Estado OGS traducido

Punto desde donde se imprimió

Tiempo de espera

Estado Oracle

Auto-refresh configurable

Límite de filas configurable

🛠️ Tecnologías utilizadas

PHP 8.2

MySQL / MariaDB

Oracle 19c (Instant Client 23.x)

OCI8 para conexión Oracle

HTML + CSS básico

⚙️ Requisitos
Backend

PHP 8.x

Extensión OCI8 habilitada

Oracle Instant Client (23.x recomendado)

Servidor web (Apache recomendado)

Acceso a:

Base MySQL NemoQ

Base Oracle SFere
