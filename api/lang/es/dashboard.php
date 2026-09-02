<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [
    'title' => 'Costo de la cesta infantil',
    'tagline' => 'Lo que cuesta cubrir las necesidades básicas de un niño durante un mes, medido donde vive la gente.',

    'headline_median' => 'Mediana entre localidades comparables',
    'headline_usd' => 'En dólares estadounidenses',
    'headline_spread' => 'De la más barata a la más cara',
    'headline_locations' => 'Localidades comparables',
    'as_of' => 'Al :date',
    'no_data' => 'Todavía no hay cifras publicadas.',
    'no_comparable' => 'Ninguna localidad tiene la cesta completamente valorada, así que no hay una mediana comparable que publicar. Las cifras de abajo siguen siendo exactas para cada localidad por separado.',
    'no_data_body' => 'Aparecerán aquí cuando se reporten precios y se calcule el índice.',

    'map_title' => 'Dónde cuesta más',
    'map_desc' => 'Cada punto es una localidad que reporta, sombreada según el costo de la misma cesta. Los puntos huecos no son comparables — véase abajo.',
    'map_alt' => 'Mapa de localidades que reportan, sombreadas según el costo de la cesta.',
    'legend_cheaper' => 'Menos costoso',
    'legend_dearer' => 'Más costoso',
    'legend_incomparable' => 'No comparable',

    'comparable_note' => 'No comparable',
    'comparable_explain' => 'Parte de esta cesta no tiene precio, así que su total no se mide en igualdad de condiciones con una localidad completamente valorada. Una cesta parcialmente valorada cuesta menos simplemente porque le falta una parte, lo que haría parecer barato a un lugar con pocos reportes. Esas localidades se muestran, pero no se clasifican.',

    'quality' => 'Calidad de los datos',
    'quality_good' => 'Buena',
    'quality_moderate' => 'Moderada',
    'quality_low' => 'Baja',
    'coverage' => 'Cobertura',
    'imputed' => 'Estimado',
    'imputed_explain' => 'Proporción de la cesta valorada por modelo en lugar de observada. Los valores estimados nunca se presentan como mediciones.',
    'observed' => 'Observado',

    'chart_national' => 'Costo de la cesta en el tiempo',
    'chart_national_desc' => 'Mediana entre las localidades con la cesta completamente valorada.',
    'chart_await' => 'Todavía no hay línea: una tendencia necesita dos fechas en las que algún lugar haya valorado la cesta completa.',
    'chart_locations' => 'Por localidad',
    'chart_fx' => 'Tipo de cambio',
    'chart_fx_desc' => 'Tasas oficial y paralela. La brecha entre ambas suele ser la primera señal visible de tensión económica.',
    'chart_premium' => 'Prima del mercado paralelo',
    'chart_official' => 'Oficial',
    'chart_parallel' => 'Paralelo',
    'chart_unavailable' => 'Aún no hay suficiente historial para graficar.',

    'table_title' => 'Todas las localidades',
    'table_location' => 'Localidad',
    'table_cost' => 'Costo de la cesta',
    'table_coverage' => 'Cobertura',
    'table_quality' => 'Calidad',
    'table_updated' => 'Actualizado',
    'days_ago' => 'hace :count día|hace :count días',
    'today' => 'Hoy',

    'use_the_data' => 'Usa estos datos',
    'use_the_data_body' => 'Todo lo que hay en esta página está disponible mediante una API pública que no requiere clave, y como descarga CSV. Los datos son CC BY 4.0; el software es Apache-2.0.',
    'spec_note' => 'El campo resaltado viaja en cada respuesta. Una estimación siempre se señala como tal, en la API igual que en esta página.',
    'api_link' => 'Documentación de la API',
    'json_link' => 'Esta página en JSON',
    'csv_link' => 'Descargar CSV',
    'source_link' => 'Código fuente',

    'hero_items' => 'Artículos con precio',
    'hero_locations' => 'Ubicaciones con datos',
    'hero_updated' => 'Datos más recientes',

    'basket_title' => 'Lo que necesita un niño',
    'basket_desc' => 'La canasta que se está costeando, empezando por el artículo de mayor peso. El peso es la proporción del gasto de un hogar en un niño que representa el artículo, de modo que un artículo sin precio cuesta mucho más al índice arriba de esta lista que abajo.',
    'basket_item' => 'Artículo',
    'basket_weight' => 'Peso',
    'basket_where' => 'Con precio en',
    'basket_locations' => ':count de :total ubicaciones',
    'basket_none' => 'Sin precio en ninguna parte',
    'basket_stack_label' => 'El :percent% de la canasta de un niño, por peso, no tiene precio en ninguna ubicación aquí.',
    'basket_gap' => ':count de :total artículos que necesita un niño no tienen precio en ninguna ubicación aquí. Esa es la brecha que esta plataforma existe para cerrar.',

    'footer_license' => 'Datos :license · Software Apache-2.0 · Las cifras se republican libremente con atribución.',

    'language' => 'Idioma',
    'skip_to_content' => 'Saltar al contenido',
];
