<?php

namespace Zweipro\Toolbox\Core;

interface ModuleInterface
{
    /**
     * Eindeutige ID, z.B. "code_snippets"
     */
    public function get_id(): string;

    /**
     * Titel für Backend
     */
    public function get_title(): string;

    /**
     * Kurze Beschreibung
     */
    public function get_description(): string;

    /**
     * Wird auf allen Requests geladen (Hooks etc.)
     */
    public function init(): void;

    /**
     * Backend-Registrierung, z.B. Settings-Pages
     *
     * @param string $parent_slug Slug des ZWEIPRO-Hauptmenüs
     */
    public function register_admin_page(string $parent_slug): void;
}