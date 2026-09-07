# Spec — keyword-storage-scope

## Purpose

Define el alcance por storage de cada keyword: qué medios se revisan en busca de cada palabra del cliente. El cliente administra este alcance; la concesión admin `transcription_access` actúa como frontera dura que nunca puede superarse.

## Requirements

### Requirement: El cliente asigna cada keyword a los stores donde debe rastrearse
El sistema SHALL permitir al cliente autenticado asignar cada una de sus keywords a uno, varios o todos sus storages, mediante filas en `user_keyword_storage`. Un keyword sin filas de asignación SHALL rastrear en TODOS los storages donde el usuario tiene `transcription_access = true` (retrocompatible).

#### Scenario: Keyword sin asignación explícita rastrea en todos sus stores con acceso
- **WHEN** el cliente registra "concierto" sin asignar stores
- **THEN** la keyword se rastrea en todos los storages del cliente con `transcription_access = true`

#### Scenario: Keyword asignada a un subconjunto
- **WHEN** el cliente asigna "concierto" solo a los storages 11 y 14
- **THEN** la keyword se rastrea únicamente en las transcripciones de esos storages, aunque tenga acceso a más

#### Scenario: El alcance nunca supera la concesión del admin
- **WHEN** el cliente tiene `transcription_access = true` solo en el storage 11 e intenta asignar una keyword al storage 14
- **THEN** el sistema rechaza la asignación (el storage 14 no está entre sus storages con acceso)

#### Scenario: Revocar acceso deja sin efecto el alcance asignado
- **WHEN** el admin revoca `transcription_access` de un storage que estaba asignado a una keyword del cliente
- **THEN** esa keyword deja de rastrear en ese storage sin necesidad de editar la asignación (la intersección se evalúa en cada scan)

### Requirement: Solo el cliente gestiona el alcance de sus keywords
El sistema SHALL restringir la creación, edición y eliminación de keywords y sus asignaciones de store al cliente autenticado dentro de su cupo. El admin define cupos y concesiones de acceso, pero no edita keywords ajenas.

#### Scenario: El cliente administra con libertad dentro del cupo
- **WHEN** el cliente crea, edita, elimina o reasigna stores de sus keywords sin exceder `keywords_quota`
- **THEN** las operaciones se aplican inmediatamente y afectan el rastreo a partir de la siguiente transcripción

#### Scenario: El cupo no se multiplica por storage
- **WHEN** el cliente tiene `keywords_quota=200` y asigna una keyword a 5 storages
- **THEN** sigue contando como 1 keyword de su cupo