<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

return [
    'title' => 'Reportar un precio',
    'subtitle' => 'Un precio que viste hoy. Aparece en el mapa público en un minuto.',

    'location' => '¿Dónde estás?',
    'location_placeholder' => 'Elige la localidad más cercana',
    'item' => '¿Qué producto valoraste?',
    'item_search' => 'Busca o escribe el producto',
    'item_free_text' => '¿No está en la lista? Escríbelo tal como aparece.',
    'price' => 'Precio',
    'unit' => 'Unidad',
    'priced_for' => 'por :quantity :unit',
    'quantity' => 'Cantidad',

    'need_headline' => ':count de :total artículos no tienen precio en ninguna localidad. El tuyo sería el primero.',
    'need_badge' => 'Primer precio',
    'need_none' => 'Cada artículo tiene precio en algún sitio — añade el de hoy.',
    'meter_label' => 'La cesta, artículo por artículo. Una barra hueca no tiene precio en ningún sitio.',
    'meter_filled' => 'Has rellenado :count de estas barras.',
    'pick_item' => 'Elige lo que valoraste',
    'change_item' => 'Cambiar',
    'details' => 'Cantidad y unidad',
    'sent_total' => 'Precios enviados desde este dispositivo: :count',

    'hint_location' => 'Elige primero la localidad más cercana.',
    'hint_item' => 'Elige lo que valoraste, o escríbelo.',
    'hint_price' => 'Introduce el precio que pagaste.',

    'submit' => 'Guardar precio',
    'saving' => 'Guardando…',

    'queued' => 'Guardado. Se enviará cuando tengas señal.',
    'queued_detail' => 'Guardado :item a :price. Se enviará cuando tengas señal.',
    'queued_first' => 'Guardado :item a :price: el primer precio que alguien ha registrado para ello.',
    'synced' => ':count precio(s) enviado(s).',
    'failed' => ':count envío(s) requieren atención.',

    'status_online' => 'En línea',
    // Says where the data is, not merely that the network is gone — the whole
    // point is that nothing has been lost.
    'status_offline' => 'Sin conexión — tus registros se guardan en este dispositivo',
    'queue_pending' => ':count esperando envío',
    'queue_failed' => ':count no se pudieron enviar',
    'nothing_queued' => 'Todo enviado',
    'reporter_id' => 'Reportero :id',
    'load_error' => 'No se pudo cargar la lista de productos y no hay una copia guardada. Conéctate una vez para configurarla.',

    'offline_title' => 'Estás sin conexión',
    'offline_body' => 'Abre el reportero para seguir registrando precios. Se enviarán solos cuando tengas señal.',
    'offline_action' => 'Ir al reportero',
];
