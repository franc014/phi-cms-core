<?php

return [
    'blog' => 'Blog',
    'cms' => 'CMS',
    'business_content' => 'Contenido de Negocio',
    'basic_info' => 'Información Básica',
    'content' => 'Contenido',
    'seo' => 'SEO',

    // Form fields
    'title' => 'Título',
    'slug' => 'Slug',
    'excerpt' => 'Extracto',
    'tags' => 'Etiquetas',
    'published_at' => 'Fecha de Publicación',
    'description' => 'Descripción',
    'route' => 'Ruta',
    'type' => 'Tipo',

    // Page form
    'main_information' => 'Información Principal',
    'general_information_about_the_page' => 'Información general sobre la página',
    'page_content' => 'Contenido de la Página',
    'only_active_sections_are_displayed_to_compose_the_page' => 'Solo las secciones activas se muestran para componer la página.',
    'sections' => 'Secciones',
    'search_for_a_section' => 'Buscar una sección',
    'no_sections_found' => 'No se encontraron secciones.',

    // Section form
    'general_information' => 'Información General',
    'section_content' => 'Contenido de la Sección',
    'section_images' => 'Imágenes de la Sección',
    'loading_images' => 'Cargando imágenes...',
    'from_component_library' => 'Desde la Biblioteca de Componentes',

    // Metatags
    'metatags' => 'Meta Etiquetas',
    'meta_tag' => 'Meta Etiqueta',
    'add_meta_tag' => 'Añadir Meta Etiqueta',
    'meta_tag_value' => 'Valor de Meta Etiqueta',

    // Default metatag keys
    'og_title' => 'Título OG',
    'og_description' => 'Descripción OG',
    'og_image' => 'Imagen OG',
    'twitter_card' => 'Twitter Card',
    'twitter_title' => 'Título Twitter',
    'twitter_description' => 'Descripción Twitter',
    'twitter_image' => 'Imagen Twitter',
    'robots' => 'Robots',

    // Menu items
    'menu_item' => 'Elemento de Menú',
    'sub_menu_items' => 'Sub Elementos de Menú',
    'the_subitems_of_the_menu_item' => 'Los subelementos del elemento de menú',
    'label' => 'Etiqueta',
    'url' => 'URL',

    // Model labels
    'post' => 'Publicación',
    'posts' => 'Publicaciones',
    'page' => 'Página',
    'pages' => 'Páginas',
    'section' => 'Sección',
    'sections' => 'Secciones',
    'menu' => 'Menú',
    'menus' => 'Menús',
    'category' => 'Categoría',
    'categories' => 'Categorías',

    // Table columns
    'status' => 'Estado',
    'created_at' => 'Creado En',
    'updated_at' => 'Actualizado En',

    // Actions
    'manage_sections' => 'Gestionar Secciones',
    'preview_page' => 'Vista previa de la página',
    'activate' => 'Activar',
    'deactivate' => 'Desactivar',
    'publish' => 'Publicar',
    'unpublish' => 'Despublicar',
    'archive' => 'Archivar',
    'restore' => 'Restaurar',
    'disable' => 'Deshabilitar',
    'enable' => 'Habilitar',

    // Status enum
    'draft' => 'Borrador',
    'published' => 'Publicado',
    'active' => 'Activo',
    'inactive' => 'Inactivo',
    'archived' => 'Archivado',
    'disabled' => 'Deshabilitado',

    // Content blocks
    'heading' => 'Encabezado',
    'paragraph' => 'Párrafo',
    'rich_editor' => 'Editor Enriquecido',
    'heading_content' => 'Encabezado',
    'heading_level' => 'Nivel de Encabezado',
    'paragraph_content' => 'Párrafo',
    'rich_editor_content' => 'Contenido',
    'heading_1' => 'Encabezado 1',
    'heading_2' => 'Encabezado 2',
    'heading_3' => 'Encabezado 3',
    'heading_4' => 'Encabezado 4',
    'heading_5' => 'Encabezado 5',
    'heading_6' => 'Encabezado 6',
    'handle' => 'Handle',
    'posts_chooser' => 'Selecciona publicaciones',

    // Settings
    'settings' => 'Configuración',
    'company_settings' => 'Configuración de la Empresa',
    'phone' => 'Teléfono',
    'address' => 'Dirección',
    'social_media' => 'Redes Sociales',
    'link' => 'Enlace',
    'social_media_name' => 'Nombre de Red Social',
    'social_media_link' => 'Enlace de Red Social',
    'working_days' => 'Días Laborales',
    'day' => 'Día',
    'hours' => 'Horas',
    'monday' => 'Lunes',

    // Protecciones del flujo heredado
    'adopted_section_editor' => 'Las secciones adoptadas deben utilizar el editor de borradores basado en definiciones. Las ediciones heredadas de metadatos, estado y medios están deshabilitadas.',
    'legacy_services_conversion' => 'No se admite cambiar contenido heredado a Servicios después de la adopción. Utiliza el flujo basado en definiciones.',
    'legacy_services_generator' => 'No se permite crear o replicar Servicios mediante generadores heredados después de la adopción.',
    'services_creation_workflow_required' => 'Crea las secciones Services desde un borrador de Página. El generador heredado de Secciones está deshabilitado mientras la edición de Páginas está activa.',
    'recovery_protected_section_delete' => 'La sección tiene referencias y no se puede eliminar. Usos en páginas: :usages.',
    'none' => '(ninguna)',
    'adopted_section_placements_read_only' => 'Las asignaciones de secciones adoptadas son de solo lectura hasta que se instale el flujo de composición de páginas.',
    'services_draft_editor_required' => 'Utiliza el editor de borradores de Servicios. Las mutaciones heredadas de contenido, metadatos, estado y medios están deshabilitadas.',
    'section_placements_changed' => 'Las asignaciones de secciones cambiaron mientras esta acción esperaba. Actualiza la página e inténtalo de nuevo.',
    'protected_section_conversions' => 'Las conversiones de secciones adoptadas y protegidas para recuperación no se pueden regenerar mediante rutas heredadas.',
    'protected_section_media' => 'Los medios de la sección están protegidos por adopción/recuperación. Las mutaciones heredadas de medios están deshabilitadas.',
];
