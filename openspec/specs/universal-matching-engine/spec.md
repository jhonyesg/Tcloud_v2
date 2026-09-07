# Spec — universal-matching-engine

## Purpose

Define el motor de rastreo de menciones: una sola pasada de escaneo por transcripción para todas las keywords distintas, con coincidencias compartidas entre clientes y reparto relacional por usuario. Elimina la duplicación de trabajo cuando clientes comparten keywords o stores.

## Requirements

### Requirement: El scan de una transcripción se ejecuta una sola vez para todas las keywords distintas
El sistema SHALL, al completarse una transcripción, calcular el conjunto de keywords distintas de todos los usuarios habilitados con `transcription_access` sobre el storage de esa transcripción (considerando el alcance keyword→store), y escanear sus segmentos una sola vez contra ese conjunto. El número de pasadas de texto SHALL ser independiente del número de usuarios.

#### Scenario: Dos clientes con la misma keyword sobre el mismo store
- **WHEN** los clientes A y B tienen la keyword "caracol" con acceso al storage 11 y termina una transcripción de 800 segmentos del storage 11
- **THEN** el texto se escanea una sola vez contra "caracol" y ambos clientes derivan el resultado de la misma coincidencia compartida

#### Scenario: Keywords distintas se acumulan en el conjunto del scan
- **WHEN** el cliente A tiene "caracol" y "petro", y el cliente B tiene "caracol" y "alcaldía", todos con acceso al storage
- **THEN** el conjunto del scan contiene las 3 keywords distintas ("caracol", "petro", "alcaldía"), no 4 keywords de usuario

### Requirement: Las coincidencias se persisten compartidas e idempotentes
El sistema SHALL persistir cada coincidencia en una tabla compartida con unicidad por (transcripción, segmento, keyword), de modo que la coincidencia exista una sola vez sin importar cuántos usuarios la reciban, y re-ejecutar el scan no duplique filas.

#### Scenario: Coincidencia compartida se guarda una vez
- **WHEN** 3 clientes reciben el match de "caracol" en el segmento 5 de la misma transcripción
- **THEN** existe una sola fila de coincidencia para (esa transcripción, segmento 5, keyword caracol) y cada cliente la referencia por la intersección de su acceso

#### Scenario: Re-ejecución no duplica
- **WHEN** el scan se re-ejecuta para la misma transcripción
- **THEN** no se crean filas de coincidencia nuevas ni notificaciones duplicadas

### Requirement: El resultado visible por usuario se deriva por acceso
El sistema SHALL derivar, para cada usuario, las coincidencias que le corresponden mediante la intersección: keywords del usuario ∩ storages con `transcription_access = true` ∩ asignación keyword→store del usuario. Un usuario nunca ve coincidencias fuera de esa intersección.

#### Scenario: Cliente sin acceso no deriva coincidencias
- **WHEN** la transcripción del storage 11 tiene coincidencias y el cliente no tiene `transcription_access` en el storage 11
- **THEN** el feed, el histórico y la entrega de correo de ese cliente excluyen esas coincidencias

#### Scenario: Cliente con keyword asignada a otro store no deriva coincidencias
- **WHEN** la keyword "concierto" del cliente está asignada solo al storage 14 y hay coincidencias en una transcripción del storage 11
- **THEN** esas coincidencias no aparecen para ese cliente aunque tenga acceso al storage 11

### Requirement: El scan no ejecuta queries por coincidencia ni envía correo
El sistema SHALL resolver los identificadores de keywords y las asignaciones antes del escaneo, sin consultas por coincidencia detectada, y SHALL NO enviar correo durante el scan (la entrega la gestiona la cadencia).

#### Scenario: Scan de transcripción con muchas coincidencias
- **WHEN** una transcripción produce 500 coincidencias
- **THEN** el scan no ejecuta una consulta SQL por cada coincidencia detectada y termina sin enviar ningún email