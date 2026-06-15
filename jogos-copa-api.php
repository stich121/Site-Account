<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

date_default_timezone_set('America/Sao_Paulo');

$jogos = [
    [
        'data' => '2026-06-13T19:00:00-03:00',
        'grupo' => 'Grupo C',
        'mandante' => 'Brasil',
        'visitante' => 'Marrocos',
        'estadio' => 'New York New Jersey Stadium',
        'destaque' => true
    ],
    [
        'data' => '2026-06-13T22:00:00-03:00',
        'grupo' => 'Grupo C',
        'mandante' => 'Haiti',
        'visitante' => 'Escócia',
        'estadio' => 'Boston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-14T01:00:00-03:00',
        'grupo' => 'Grupo D',
        'mandante' => 'Austrália',
        'visitante' => 'Turquia',
        'estadio' => 'BC Place Vancouver',
        'destaque' => false
    ],
    [
        'data' => '2026-06-14T14:00:00-03:00',
        'grupo' => 'Grupo E',
        'mandante' => 'Costa do Marfim',
        'visitante' => 'Equador',
        'estadio' => 'Philadelphia Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-14T17:00:00-03:00',
        'grupo' => 'Grupo E',
        'mandante' => 'Alemanha',
        'visitante' => 'Curaçao',
        'estadio' => 'Kansas City Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-14T20:00:00-03:00',
        'grupo' => 'Grupo F',
        'mandante' => 'Holanda',
        'visitante' => 'Japão',
        'estadio' => 'Dallas Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-15T13:00:00-03:00',
        'grupo' => 'Grupo F',
        'mandante' => 'Nova Zelândia',
        'visitante' => 'Tunísia',
        'estadio' => 'Houston Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-15T16:00:00-03:00',
        'grupo' => 'Grupo G',
        'mandante' => 'Bélgica',
        'visitante' => 'Egito',
        'estadio' => 'Los Angeles Stadium',
        'destaque' => false
    ],
    [
        'data' => '2026-06-18T19:00:00-03:00',
        'grupo' => 'Grupo C',
        'mandante' => 'Brasil',
        'visitante' => 'Haiti',
        'estadio' => 'Philadelphia Stadium',
        'destaque' => true
    ],
    [
        'data' => '2026-06-24T22:00:00-03:00',
        'grupo' => 'Grupo C',
        'mandante' => 'Brasil',
        'visitante' => 'Escócia',
        'estadio' => 'Miami Stadium',
        'destaque' => true
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

$proximos = array_slice($proximos, 0, 4);

echo json_encode([
    'status' => 'success',
    'timezone' => 'America/Sao_Paulo',
    'updated_at' => $agora->format(DateTimeInterface::ATOM),
    'source' => 'Agenda local da Copa servida por API PHP',
    'matches' => $proximos
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
