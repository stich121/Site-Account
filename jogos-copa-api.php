<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

date_default_timezone_set('America/Sao_Paulo');

$jogos = [
    [
        'data' => '2026-06-16T16:00:00-03:00',
        'grupo' => 'Grupo I',
        'mandante' => 'França',
        'visitante' => 'Senegal',
        'estadio' => 'New York New Jersey Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-16T19:00:00-03:00',
        'grupo' => 'Grupo I',
        'mandante' => 'Iraque',
        'visitante' => 'Noruega',
        'estadio' => 'Boston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-16T22:00:00-03:00',
        'grupo' => 'Grupo J',
        'mandante' => 'Argentina',
        'visitante' => 'Argélia',
        'estadio' => 'Kansas City Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-17T01:00:00-03:00',
        'grupo' => 'Grupo J',
        'mandante' => 'Áustria',
        'visitante' => 'Jordânia',
        'estadio' => 'San Francisco Bay Area Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-17T14:00:00-03:00',
        'grupo' => 'Grupo K',
        'mandante' => 'Portugal',
        'visitante' => 'RD Congo',
        'estadio' => 'Houston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-17T17:00:00-03:00',
        'grupo' => 'Grupo L',
        'mandante' => 'Inglaterra',
        'visitante' => 'Croácia',
        'estadio' => 'Dallas Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-17T20:00:00-03:00',
        'grupo' => 'Grupo L',
        'mandante' => 'Gana',
        'visitante' => 'Panamá',
        'estadio' => 'Toronto Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-17T23:00:00-03:00',
        'grupo' => 'Grupo K',
        'mandante' => 'Uzbequistão',
        'visitante' => 'Colômbia',
        'estadio' => 'Mexico City Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-18T13:00:00-03:00',
        'grupo' => 'Grupo A',
        'mandante' => 'Tchéquia',
        'visitante' => 'África do Sul',
        'estadio' => 'Atlanta Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-18T16:00:00-03:00',
        'grupo' => 'Grupo B',
        'mandante' => 'Suíça',
        'visitante' => 'Bósnia e Herzegovina',
        'estadio' => 'Los Angeles Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-18T19:00:00-03:00',
        'grupo' => 'Grupo B',
        'mandante' => 'Canadá',
        'visitante' => 'Qatar',
        'estadio' => 'BC Place Vancouver',
        'destaque' => false
    ],
    [
        'data' => '2026-06-18T22:00:00-03:00',
        'grupo' => 'Grupo A',
        'mandante' => 'México',
        'visitante' => 'Coreia do Sul',
        'estadio' => 'Mexico City Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-19T16:00:00-03:00',
        'grupo' => 'Grupo D',
        'mandante' => 'Estados Unidos',
        'visitante' => 'Austrália',
        'estadio' => 'Seattle Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-19T19:00:00-03:00',
        'grupo' => 'Grupo C',
        'mandante' => 'Escócia',
        'visitante' => 'Marrocos',
        'estadio' => 'Atlanta Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-19T21:30:00-03:00',
        'grupo' => 'Grupo C',
        'mandante' => 'Brasil',
        'visitante' => 'Haiti',
        'estadio' => 'Philadelphia Stadium',
        'destaque' => true
    ],
    [
        'data' => '2026-06-20T00:00:00-03:00',
        'grupo' => 'Grupo D',
        'mandante' => 'Türkiye',
        'visitante' => 'Paraguai',
        'estadio' => 'Los Angeles Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-20T14:00:00-03:00',
        'grupo' => 'Grupo F',
        'mandante' => 'Holanda',
        'visitante' => 'Suécia',
        'estadio' => 'Dallas Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-20T17:00:00-03:00',
        'grupo' => 'Grupo E',
        'mandante' => 'Alemanha',
        'visitante' => 'Costa do Marfim',
        'estadio' => 'Houston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-20T21:00:00-03:00',
        'grupo' => 'Grupo E',
        'mandante' => 'Equador',
        'visitante' => 'Curaçao',
        'estadio' => 'Philadelphia Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-21T01:00:00-03:00',
        'grupo' => 'Grupo F',
        'mandante' => 'Tunísia',
        'visitante' => 'Japão',
        'estadio' => 'Monterrey Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-21T13:00:00-03:00',
        'grupo' => 'Grupo H',
        'mandante' => 'Espanha',
        'visitante' => 'Arábia Saudita',
        'estadio' => 'Atlanta Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-21T16:00:00-03:00',
        'grupo' => 'Grupo G',
        'mandante' => 'Bélgica',
        'visitante' => 'Irã',
        'estadio' => 'Seattle Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-21T19:00:00-03:00',
        'grupo' => 'Grupo H',
        'mandante' => 'Uruguai',
        'visitante' => 'Cabo Verde',
        'estadio' => 'Miami Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-21T22:00:00-03:00',
        'grupo' => 'Grupo G',
        'mandante' => 'Nova Zelândia',
        'visitante' => 'Egito',
        'estadio' => 'Los Angeles Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-22T14:00:00-03:00',
        'grupo' => 'Grupo J',
        'mandante' => 'Argentina',
        'visitante' => 'Áustria',
        'estadio' => 'Dallas Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-22T18:00:00-03:00',
        'grupo' => 'Grupo I',
        'mandante' => 'França',
        'visitante' => 'Iraque',
        'estadio' => 'Philadelphia Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-22T21:00:00-03:00',
        'grupo' => 'Grupo I',
        'mandante' => 'Noruega',
        'visitante' => 'Senegal',
        'estadio' => 'New York New Jersey Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-23T00:00:00-03:00',
        'grupo' => 'Grupo J',
        'mandante' => 'Jordânia',
        'visitante' => 'Argélia',
        'estadio' => 'San Francisco Bay Area Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-23T14:00:00-03:00',
        'grupo' => 'Grupo K',
        'mandante' => 'Portugal',
        'visitante' => 'Uzbequistão',
        'estadio' => 'Houston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-23T17:00:00-03:00',
        'grupo' => 'Grupo L',
        'mandante' => 'Inglaterra',
        'visitante' => 'Gana',
        'estadio' => 'Boston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-23T20:00:00-03:00',
        'grupo' => 'Grupo L',
        'mandante' => 'Panamá',
        'visitante' => 'Croácia',
        'estadio' => 'Toronto Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-23T23:00:00-03:00',
        'grupo' => 'Grupo K',
        'mandante' => 'Colômbia',
        'visitante' => 'RD Congo',
        'estadio' => 'Guadalajara Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-24T16:00:00-03:00',
        'grupo' => 'Grupo B',
        'mandante' => 'Suíça',
        'visitante' => 'Canadá',
        'estadio' => 'BC Place Vancouver',
        'destaque' => false
    ],
    [
        'data' => '2026-06-24T16:00:00-03:00',
        'grupo' => 'Grupo B',
        'mandante' => 'Bósnia e Herzegovina',
        'visitante' => 'Qatar',
        'estadio' => 'Seattle Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-24T19:00:00-03:00',
        'grupo' => 'Grupo C',
        'mandante' => 'Marrocos',
        'visitante' => 'Haiti',
        'estadio' => 'Atlanta Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-24T19:00:00-03:00',
        'grupo' => 'Grupo C',
        'mandante' => 'Escócia',
        'visitante' => 'Brasil',
        'estadio' => 'Miami Stadium',
        'destaque' => true
    ],
    [
        'data' => '2026-06-24T22:00:00-03:00',
        'grupo' => 'Grupo A',
        'mandante' => 'África do Sul',
        'visitante' => 'Coreia do Sul',
        'estadio' => 'Guadalajara Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-24T22:00:00-03:00',
        'grupo' => 'Grupo A',
        'mandante' => 'Tchéquia',
        'visitante' => 'México',
        'estadio' => 'Mexico City Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-25T17:00:00-03:00',
        'grupo' => 'Grupo E',
        'mandante' => 'Curaçao',
        'visitante' => 'Costa do Marfim',
        'estadio' => 'Philadelphia Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-25T17:00:00-03:00',
        'grupo' => 'Grupo E',
        'mandante' => 'Equador',
        'visitante' => 'Alemanha',
        'estadio' => 'Houston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-25T20:00:00-03:00',
        'grupo' => 'Grupo F',
        'mandante' => 'Tunísia',
        'visitante' => 'Holanda',
        'estadio' => 'Monterrey Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-25T20:00:00-03:00',
        'grupo' => 'Grupo F',
        'mandante' => 'Japão',
        'visitante' => 'Suécia',
        'estadio' => 'Dallas Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-25T23:00:00-03:00',
        'grupo' => 'Grupo D',
        'mandante' => 'Türkiye',
        'visitante' => 'Estados Unidos',
        'estadio' => 'Los Angeles Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-25T23:00:00-03:00',
        'grupo' => 'Grupo D',
        'mandante' => 'Paraguai',
        'visitante' => 'Austrália',
        'estadio' => 'BC Place Vancouver',
        'destaque' => false
    ],
    [
        'data' => '2026-06-26T16:00:00-03:00',
        'grupo' => 'Grupo I',
        'mandante' => 'Noruega',
        'visitante' => 'França',
        'estadio' => 'New York New Jersey Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-26T16:00:00-03:00',
        'grupo' => 'Grupo I',
        'mandante' => 'Senegal',
        'visitante' => 'Iraque',
        'estadio' => 'Boston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-26T21:00:00-03:00',
        'grupo' => 'Grupo H',
        'mandante' => 'Cabo Verde',
        'visitante' => 'Arábia Saudita',
        'estadio' => 'Atlanta Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-26T21:00:00-03:00',
        'grupo' => 'Grupo H',
        'mandante' => 'Uruguai',
        'visitante' => 'Espanha',
        'estadio' => 'Miami Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-27T00:00:00-03:00',
        'grupo' => 'Grupo G',
        'mandante' => 'Nova Zelândia',
        'visitante' => 'Bélgica',
        'estadio' => 'Seattle Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-27T00:00:00-03:00',
        'grupo' => 'Grupo G',
        'mandante' => 'Egito',
        'visitante' => 'Irã',
        'estadio' => 'Los Angeles Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-27T18:00:00-03:00',
        'grupo' => 'Grupo L',
        'mandante' => 'Panamá',
        'visitante' => 'Inglaterra',
        'estadio' => 'Toronto Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-27T18:00:00-03:00',
        'grupo' => 'Grupo L',
        'mandante' => 'Croácia',
        'visitante' => 'Gana',
        'estadio' => 'Boston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-27T20:30:00-03:00',
        'grupo' => 'Grupo K',
        'mandante' => 'Colômbia',
        'visitante' => 'Portugal',
        'estadio' => 'Guadalajara Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-27T20:30:00-03:00',
        'grupo' => 'Grupo K',
        'mandante' => 'RD Congo',
        'visitante' => 'Uzbequistão',
        'estadio' => 'Mexico City Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-27T23:00:00-03:00',
        'grupo' => 'Grupo J',
        'mandante' => 'Argélia',
        'visitante' => 'Áustria',
        'estadio' => 'Kansas City Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-27T23:00:00-03:00',
        'grupo' => 'Grupo J',
        'mandante' => 'Jordânia',
        'visitante' => 'Argentina',
        'estadio' => 'San Francisco Bay Area Stadium',
        'destaque' => false
    ],
];

$agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

$janelaJogoAtual = new DateInterval('PT150M');

$proximos = array_values(array_filter(array_map(function ($jogo) use ($agora, $janelaJogoAtual) {
    $inicio = new DateTimeImmutable($jogo['data']);
    $fimEstimado = $inicio->add($janelaJogoAtual);

    $jogo['status'] = ($agora >= $inicio && $agora <= $fimEstimado) ? 'ao_vivo' : 'proximo';
    $jogo['status_label'] = $jogo['status'] === 'ao_vivo' ? 'Jogo atual' : 'Próximo jogo';
    $jogo['fim_estimado'] = $fimEstimado->format(DateTimeInterface::ATOM);

    return $jogo;
}, $jogos), function ($jogo) use ($agora) {
    return new DateTimeImmutable($jogo['fim_estimado']) >= $agora;
}));

usort($proximos, function ($a, $b) {
    return strtotime($a['data']) <=> strtotime($b['data']);
});

$proximos = array_slice($proximos, 0, 6);

echo json_encode([
    'status' => 'success',
    'timezone' => 'America/Sao_Paulo',
    'updated_at' => $agora->format(DateTimeInterface::ATOM),
    'source' => 'Agenda local da fase de grupos da Copa servida por API PHP',
    'matches' => $proximos
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
