<?php
ini_set('display_errors', '0'); error_reporting(E_ALL); // warnings no meio do JSON davam "Servidor ocupado" no jogo
// Encantados v3 - API (PHP 7.4+). Banco: PostgreSQL (Neon) via DATABASE_URL (pdo_pgsql); sem DATABASE_URL usa SQLite local (pdo_sqlite). O esquema é criado/migrado automaticamente.
$RO = in_array($_GET['a'] ?? '', ['ping', 'pvp_sync', 'pvp_invites', 'me', 'friend_list', 'clan_chat', 'missions', 'ranking', 'clans', 'player', 'admin_data', 'tour', 'tour_hall', 'ach', 'q4_sync', 'cards']);
// ONDE OS DADOS FICAM: defina DATA_DIR (ex.: /var/data, um disco permanente do Render) para o banco nunca ser apagado ao atualizar o site.
// Sem DATA_DIR, usa /var/data se existir; senão, a própria pasta do site (como antes).
$DATA = rtrim((string)(getenv('DATA_DIR') ?: ((is_dir('/var/data') && is_writable('/var/data')) ? '/var/data' : __DIR__)), '/');
if (!is_dir($DATA)) @mkdir($DATA, 0700, true);
if (!is_dir($DATA) || !is_writable($DATA)) $DATA = __DIR__;
define('DBF', $DATA . '/game.db');
define('DATA_PERM', $DATA !== __DIR__);
// 1ª vez no disco permanente: leva junto o game.db que veio com o site (jogadores atuais)
if (DATA_PERM && !is_file(DBF) && is_file(__DIR__ . '/game.db')) @copy(__DIR__ . '/game.db', DBF);
$sd = $DATA . '/.sessions'; if (!is_dir($sd)) @mkdir($sd, 0700, true);
if (is_dir($sd) && is_writable($sd)) { session_save_path($sd); ini_set('session.gc_maxlifetime', '2592000'); } // sessões próprias: não são apagadas por outros sites do mesmo servidor
session_set_cookie_params(['lifetime' => 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
session_start($RO ? ['read_and_close' => true] : []);
header('Content-Type: application/json; charset=utf-8');
set_exception_handler(function ($e) { // qualquer erro vira JSON (e fica registrado no log) em vez de página em branco
    error_log('Encantados: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['error' => 'Erro no servidor. Tente de novo em instantes.', 'retry' => 1], JSON_UNESCAPED_UNICODE); exit;
});
// ===== BANCO DE DADOS =====
// Com a variável de ambiente DATABASE_URL (ex.: Neon) o jogo usa PostgreSQL. Sem ela (vazia), usa o SQLite local (game.db) só como reserva.
const PG_SCHEMA = 15; // versão do esquema no PostgreSQL (guardada na tabela meta)
$DBURL = trim((string)(getenv('DATABASE_URL') ?: ($_SERVER['DATABASE_URL'] ?? '')));
define('USE_PG', $DBURL !== '');
define('MX', USE_PG ? 'GREATEST' : 'MAX');   // máximo entre dois valores (SQL)
define('LK', USE_PG ? 'ILIKE' : 'LIKE');     // LIKE sem diferenciar maiúsculas (SQL)
// postgresql://usuario:senha@host:porta/banco?sslmode=require&channel_binding=require  ->  DSN do PDO (channel_binding é descartado)
function pg_conn_args($url) {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['path']) || !in_array(strtolower($p['scheme'] ?? ''), ['postgres', 'postgresql', 'pgsql'], true)) throw new RuntimeException('DATABASE_URL inválida (esperado postgresql://usuario:senha@host/banco?sslmode=require)');
    parse_str($p['query'] ?? '', $qs);
    $ssl = preg_match('/^(disable|allow|prefer|require|verify-ca|verify-full)$/', (string)($qs['sslmode'] ?? '')) ? $qs['sslmode'] : (in_array($p['host'], ['localhost', '127.0.0.1'], true) ? 'prefer' : 'require');
    $dsn = 'pgsql:host=' . $p['host'] . ';port=' . (int)($p['port'] ?? 5432) . ';dbname=' . rawurldecode(ltrim($p['path'], '/')) . ';sslmode=' . $ssl;
    return [$dsn, rawurldecode($p['user'] ?? ''), rawurldecode($p['pass'] ?? '')];
}
if (USE_PG) {
    [$pgDsn, $pgUser, $pgPass] = pg_conn_args($DBURL);
    // EMULATE_PREPARES: compatível com o pooler do Neon (pgbouncer) e evita erros de tipo de parâmetro
    $db = new PDO($pgDsn, $pgUser, $pgPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_PERSISTENT => getenv('DB_PERSISTENT') === '1']);
} else {
    $db = new PDO('sqlite:' . DBF);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_TIMEOUT, 8);
    try { $db->exec('PRAGMA busy_timeout=8000'); $db->exec('PRAGMA journal_mode=WAL'); $db->exec('PRAGMA synchronous=NORMAL'); } catch (Exception $e) {}
}
// Rotas "quentes" (chamadas várias vezes por segundo na partida): pulam a checagem de esquema/ADMIN quando ela já foi feita (marcador em disco)
$HOT = in_array($_GET['a'] ?? '', ['pvp_sync', 'q4_sync', 'ping', 'pvp_invites', 'clan_chat'], true);
$PGMARK = $DATA . '/.pgv-' . substr(md5($DBURL), 0, 10) . '-' . PG_SCHEMA;
$SKIP = USE_PG && $HOT && is_file($PGMARK);
function dbv() { // versão do esquema (SQLite: user_version · PostgreSQL: tabela meta)
    global $db;
    if (!USE_PG) return (int)$db->query('PRAGMA user_version')->fetchColumn();
    try { $v = $db->query("SELECT v FROM meta WHERE k='ver'")->fetchColumn(); return $v === false ? 0 : (int)$v; } catch (Exception $e) { return 0; }
}
function dbv_set($n) {
    global $db;
    if (!USE_PG) { $db->exec('PRAGMA user_version=' . (int)$n); return; }
    $db->prepare("INSERT INTO meta(k,v) VALUES('ver',?) ON CONFLICT (k) DO UPDATE SET v=EXCLUDED.v")->execute([(string)(int)$n]);
}
function table_cols($t) { // nomes das colunas de uma tabela, na ordem
    global $db;
    if (USE_PG) return $db->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=" . $db->quote($t) . " ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    return array_column($db->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
}
// INSERT que devolve o id novo. No PostgreSQL usa RETURNING (lastInsertId() não é confiável atrás do pooler do Neon)
function qid($sql, $p = []) {
    global $db;
    if (USE_PG) return (int)q($sql . ' RETURNING id', $p)->fetchColumn();
    q($sql, $p); return (int)$db->lastInsertId();
}
function pg_lock($k) { // trava de transação (some no COMMIT/ROLLBACK): serializa filas de partida e evita salas duplicadas
    global $db;
    if (USE_PG) $db->query('SELECT pg_advisory_xact_lock(' . (int)sprintf('%u', crc32((string)$k)) . ')')->closeCursor();
}
function fu() { return USE_PG ? ' FOR UPDATE' : ''; } // trava a linha lida (PostgreSQL)
function wtx_begin() { global $db; if (USE_PG) $db->beginTransaction(); else $db->exec('BEGIN IMMEDIATE'); }
function wtx_commit() { global $db; if (USE_PG) $db->commit(); else $db->exec('COMMIT'); }
function wtx_rollback() { global $db; try { if (USE_PG) { if ($db->inTransaction()) $db->rollBack(); } else $db->exec('ROLLBACK'); } catch (Exception $e) {} }
// Migrações rodam só uma vez (antes rodavam a cada requisição e travavam o banco => jogador aparecia offline)
$MIG = !USE_PG && dbv() < 6;
if ($MIG) {
$db->exec("CREATE TABLE IF NOT EXISTS players(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE COLLATE NOCASE, pass TEXT, avatar TEXT, title TEXT, trophies INTEGER DEFAULT 0, gold INTEGER DEFAULT 200, wins INTEGER DEFAULT 0, losses INTEGER DEFAULT 0, clan_id INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS clans(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE COLLATE NOCASE, descr TEXT, leader_id INTEGER)");
foreach (["gems INTEGER DEFAULT 20", "owned TEXT", "chests TEXT", "best INTEGER DEFAULT 0", "streak INTEGER DEFAULT 0", "since INTEGER", "seen INTEGER DEFAULT 0"] as $c) { try { $db->exec("ALTER TABLE players ADD COLUMN $c"); } catch (Exception $e) {} }
}

const RANKED_MIN = 4000;
// Arenas ranqueadas: [troféus mínimos, nome]
const RANKED = [[4000, 'Liga do Cocar'], [6000, 'Liga da Pedra Pintada'], [9000, 'Liga da Lua Cheia'], [13000, 'Liga do Trovão'], [18000, 'Panteão dos Encantados']];
const EMOTES = ['e:😂', 'e:😭', 'e:😡', 'e:👍', 'e:😱', 'e:🤔', 'e:😎', 'e:🥳',
    'e:f:saci', 'e:f:curupira', 'e:f:cuca', 'e:f:boitata', 'e:f:iara', 'e:f:boto']; // + emotes rápidos do folclore (1x1 e 2v2)
const AVATARS = ['🧝', '🧙', '🦊', '🐺', '🌿', '🔥', '🧜‍♀️', '🪶', '🐆', '🦜', '🐍', '🦉', '🐊', '🦇', '🐗', '🐬', '🌙', '☀️', '⚡', '👹', '🧚', '🧛', '🧞', '🐉'];
const TITLES = ['Guardião da Mata', 'Curandeira do Sertão', 'Mestre das Águas', 'Caçador de Assombrações', 'Pajé das Estrelas', 'Domador de Boiúnas', 'Cria do Saci', 'Benzedeira de Respeito', 'Rei do Folclore', 'Rainha da Lua Cheia', 'Contador de Causos', 'Andarilho da Caatinga', 'Senhor dos Trovões', 'Feiticeiro do Mangue', 'Lenda Viva', 'Terror das Torres'];
// raridade: 0 comum, 1 raro, 2 épico, 3 lendário
const RARITY = ['saci'=>0,'negrinho'=>0,'uirapuru'=>0,'matinta'=>0,'caipora'=>0,'curupira'=>0,'iara'=>0,'cuia'=>0,'pisadeira'=>0,'anhanga'=>1,'mula'=>1,'boto'=>1,'boi'=>1,'capelobo'=>1,'corpo'=>1,'curacanga'=>1,'tutu'=>2,'ipupiara'=>2,'lobis'=>2,'cuca'=>2,'yacu'=>2,'jurupari'=>2,'boitata'=>3,'boiuna'=>3,'maeouro'=>3,'onca'=>3,'mapin'=>3,'tupa'=>4,'jaci'=>4,'guaraci'=>4,'romao'=>0,'ahoaho'=>0,'papafigo'=>1,'bradador'=>1,'alamoa'=>2,'quibungo'=>2,'labatut'=>3,'norato'=>3,'paimato'=>3,'ceuci'=>4,'flechas'=>0,'bolafogo'=>1,'veneno'=>1,'cura'=>1,'geada'=>2,'tornado'=>2,'raio'=>3,'oca'=>1,'totem'=>1,'ninho'=>2,'tapera'=>2,'cabriola'=>0,'tuiuiu'=>0,'fogofatuo'=>0,'caboclo'=>1,'maocabelo'=>1,'cachorropadre'=>1,'pegarrafa'=>2,'bichopapao'=>2,'comadre'=>0,'joaogrilo'=>0,'bemtevi'=>0,'boicara'=>0,'pernacabeluda'=>0,'catirina'=>0,'malasartes'=>1,'porca'=>1,'loira'=>1,'mboitui'=>1,'urutau'=>1,'jasy'=>1,'urubu'=>1,'minhocao'=>2,'sucuriju'=>2,'teju'=>2,'macunaima'=>2,'almapenada'=>2,'coruja'=>3,'naia'=>3,'pirarucu'=>3,'ruda'=>4,'vitoria'=>1,'pegadas'=>2,'pororoca'=>2,'farinha'=>1,'cupinzeiro'=>2];
const CARD_AR = ['saci'=>0,'negrinho'=>0,'uirapuru'=>0,'matinta'=>0,'caipora'=>0,'curupira'=>0,'iara'=>0,'cuia'=>1,'pisadeira'=>1,'anhanga'=>1,'mula'=>0,'boto'=>1,'boi'=>0,'capelobo'=>2,'corpo'=>3,'curacanga'=>4,'tutu'=>2,'ipupiara'=>5,'lobis'=>2,'cuca'=>3,'yacu'=>4,'jurupari'=>5,'boitata'=>5,'boiuna'=>6,'maeouro'=>6,'onca'=>7,'mapin'=>7,'tupa'=>8,'jaci'=>8,'guaraci'=>9,'romao'=>10,'ahoaho'=>10,'papafigo'=>11,'bradador'=>12,'alamoa'=>13,'quibungo'=>14,'labatut'=>15,'norato'=>16,'paimato'=>17,'ceuci'=>19,'flechas'=>0,'bolafogo'=>0,'veneno'=>1,'cura'=>2,'geada'=>3,'tornado'=>4,'raio'=>5,'oca'=>1,'totem'=>2,'ninho'=>3,'tapera'=>6,'cabriola'=>1,'tuiuiu'=>2,'fogofatuo'=>3,'caboclo'=>3,'maocabelo'=>4,'cachorropadre'=>5,'pegarrafa'=>6,'bichopapao'=>7,'comadre'=>0,'joaogrilo'=>1,'bemtevi'=>2,'boicara'=>2,'pernacabeluda'=>3,'catirina'=>4,'malasartes'=>1,'porca'=>3,'loira'=>4,'mboitui'=>5,'urutau'=>6,'jasy'=>7,'urubu'=>8,'minhocao'=>6,'sucuriju'=>9,'teju'=>11,'macunaima'=>12,'almapenada'=>14,'coruja'=>10,'naia'=>13,'pirarucu'=>16,'ruda'=>18,'vitoria'=>2,'pegadas'=>7,'pororoca'=>11,'farinha'=>5,'cupinzeiro'=>9]; // arena mínima de cada carta
const ARENA_T = [0,150,300,500,800,1200,1700,2300,3000,4000,5000,6200,7500,9000,10500,12000,14000,16000,18500,21000];
if (USE_PG) pg_migrate(); // PostgreSQL: cria todas as tabelas (uma vez)
if ($MIG) {
$db->exec("CREATE TABLE IF NOT EXISTS clan_msgs(id INTEGER PRIMARY KEY AUTOINCREMENT, clan_id INTEGER, player_id INTEGER, name TEXT, avatar TEXT, text TEXT, t INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS matches(id INTEGER PRIMARY KEY AUTOINCREMENT, host INTEGER, guest INTEGER, status TEXT, created INTEGER, ht INTEGER DEFAULT 0, gt INTEGER DEFAULT 0, hseen INTEGER, gseen INTEGER, result TEXT, invite INTEGER)");
$db->exec("CREATE TABLE IF NOT EXISTS friends(a INTEGER, b INTEGER, status TEXT, PRIMARY KEY(a,b))");
foreach (["clan_msgs" => "img TEXT", "matches" => "mode TEXT"] as $tb => $c) { try { $db->exec("ALTER TABLE $tb ADD COLUMN $c"); } catch (Exception $e) {} }
$db->exec("CREATE TABLE IF NOT EXISTS moves(id INTEGER PRIMARY KEY AUTOINCREMENT, match_id INTEGER, side INTEGER, tick INTEGER, card TEXT, x REAL, y REAL)");
try { $db->exec("CREATE INDEX IF NOT EXISTS moves_m ON moves(match_id,id)"); } catch (Exception $e) {}
$db->exec("PRAGMA user_version=6");
}
if (!USE_PG && dbv() < 7) {
 foreach (["dlast TEXT", "dstreak INTEGER DEFAULT 0"] as $c) { try { $db->exec("ALTER TABLE players ADD COLUMN $c"); } catch (Exception $e) {} }
 $db->exec("PRAGMA user_version=7");
}
if (!USE_PG && dbv() < 8) {
 foreach (["mday TEXT", "mbase TEXT", "mdone TEXT"] as $c) { try { $db->exec("ALTER TABLE players ADD COLUMN $c"); } catch (Exception $e) {} }
 $db->exec("PRAGMA user_version=8");
}
if (!USE_PG && dbv() < 9) {
 try { $db->exec("ALTER TABLE matches ADD COLUMN code TEXT"); } catch (Exception $e) {}
 $db->exec("PRAGMA user_version=9");
}
// Tutorial: garante a coluna "tut" (verifica a coluna de verdade, não só a versão do banco)
if (!USE_PG) {
$hasTut = false;
foreach (table_cols('players') as $col) { if ($col === 'tut') $hasTut = true; }
if (!$hasTut) {
 try {
  $db->exec("ALTER TABLE players ADD COLUMN tut INTEGER DEFAULT 0");
  $db->exec("UPDATE players SET tut=1 WHERE COALESCE(wins,0)+COALESCE(losses,0)>0");
 } catch (Exception $e) { error_log('Encantados: falha ao criar coluna tut: ' . $e->getMessage()); }
}
$cols = table_cols('players');
foreach (['cxp TEXT', 'txp INTEGER DEFAULT 0', 'adm INTEGER DEFAULT 0'] as $c) { if (!in_array(explode(' ', $c)[0], $cols)) { try { $db->exec("ALTER TABLE players ADD COLUMN $c"); } catch (Exception $e) { error_log('Encantados: coluna ' . $c . ': ' . $e->getMessage()); } } }
}
// Webhook de saída: se existir webhook.json {"url":"...","secret":"..."}, cada evento é enviado assinado (HMAC-SHA256)
$HQ = [];
function hook($ev, $d) { global $HQ; $HQ[] = [$ev, $d]; } // só enfileira; envia depois de responder ao jogador
function hook_flush() { global $HQ; foreach ($HQ as $h) hook_send($h[0], $h[1]); $HQ = []; }
function hook_send($ev, $d) {
    $f = __DIR__ . '/webhook.json'; if (!is_file($f)) return;
    $c = json_decode(file_get_contents($f), true); if (empty($c['url']) || !preg_match('#^https?://#', $c['url']) || strpos($c['url'], 'seu-servidor.com') !== false) return;
    $b = json_encode(['event' => $ev, 't' => time(), 'data' => $d], JSON_UNESCAPED_UNICODE);
    $sig = hash_hmac('sha256', $b, $c['secret'] ?? '');
    @file_get_contents($c['url'], false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 1, 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\nX-Encantados-Signature: sha256=$sig\r\n", 'content' => $b]]));
}
if (!USE_PG && dbv() < 10) {
 foreach (["items TEXT", "eqp TEXT"] as $c) { try { $db->exec("ALTER TABLE players ADD COLUMN $c"); } catch (Exception $e) {} }
 $db->exec("PRAGMA user_version=10");
}
if (!USE_PG && dbv() < 11) {
 $db->exec("CREATE TABLE IF NOT EXISTS auth_tokens(id INTEGER PRIMARY KEY AUTOINCREMENT, player_id INTEGER, hash TEXT UNIQUE, created INTEGER, last INTEGER)");
 try { $db->exec("CREATE INDEX IF NOT EXISTS tok_p ON auth_tokens(player_id)"); $db->exec("CREATE INDEX IF NOT EXISTS matches_w ON matches(status,hseen)"); } catch (Exception $e) {}
 try { $db->exec("ALTER TABLE players ADD COLUMN recov TEXT"); } catch (Exception $e) {}
 try { $db->exec("ALTER TABLE matches ADD COLUMN diff INTEGER DEFAULT 1"); } catch (Exception $e) {}
 $db->exec("PRAGMA user_version=11");
}
const RENAME = 700;
const ITEMS = ['b:mata'=>['g',400], 'b:sertao'=>['g',450], 'b:cordel'=>['g',500], 'b:bumba'=>['g',600], 'b:rio'=>['g',650], 'b:saci'=>['g',700], 'b:ipe'=>['g',800], 'b:pampa'=>['g',900], 'b:iara'=>['d',90], 'b:boitata'=>['d',110], 'b:curupira'=>['d',130], 'b:tupa'=>['d',150], 'b:boto'=>['d',170], 'b:cuca'=>['d',200], 'm:saci'=>['g',250], 'm:curupira'=>['g',300], 'm:caipora'=>['g',350], 'm:cuia'=>['g',400], 'm:uirapuru'=>['g',450], 'm:mula'=>['g',500], 'm:boto'=>['g',550], 'm:negrinho'=>['g',600], 'm:iara'=>['d',45], 'm:boitata'=>['d',55], 'm:lobis'=>['d',65], 'm:cuca'=>['d',75], 'm:tupa'=>['d',85], 'm:jaci'=>['d',95], 'm:mapin'=>['d',105], 'm:ceuci'=>['d',120], 't:oca'=>['g',1200], 't:pedra'=>['g',1500], 't:barro'=>['g',1800], 't:vitoria'=>['g',2200], 't:boitata'=>['d',220], 't:iara'=>['d',280], 't:jaci'=>['d',340], 't:cuca'=>['d',420]]; // g = moedas (simples), d = diamantes (exclusivos)
// ================= MISSÕES DIÁRIAS AUTOMÁTICAS =================
// Cada jogador tem 3 missões por vez (1 fácil, 1 média, 1 desafio). A série é gerada sozinha na primeira atividade depois que a anterior vence
// e dura 24 h (MIS_TTL). O progresso é contado no SERVIDOR, a partir dos fins de partida (battle_end / pvp_end / q4_end), e o resgate
// (mission_claim) é atômico: a mesma recompensa nunca é paga duas vezes, nem com dois cliques ou duas abas.
const MIS_TTL = 86400;
const MIS_ELEM = ['fogo' => ['🔥', 'Fogo'], 'agua' => ['🌊', 'Água'], 'mata' => ['🌿', 'Mata'], 'noite' => ['🌙', 'Noite'], 'vento' => ['⚡', 'Vento e Trovão']];
const ELEM_OF = ['saci'=>'vento','negrinho'=>'fogo','uirapuru'=>'vento','matinta'=>'noite','caipora'=>'mata','curupira'=>'fogo','iara'=>'agua','cuia'=>'agua','pisadeira'=>'noite','anhanga'=>'mata','mula'=>'fogo','boto'=>'agua','boi'=>'mata','capelobo'=>'noite','corpo'=>'noite','curacanga'=>'fogo','tutu'=>'noite','ipupiara'=>'agua','lobis'=>'noite','cuca'=>'noite','yacu'=>'agua','jurupari'=>'mata','boitata'=>'fogo','boiuna'=>'agua','maeouro'=>'fogo','onca'=>'mata','mapin'=>'mata','tupa'=>'vento','jaci'=>'noite','guaraci'=>'fogo','romao'=>'fogo','ahoaho'=>'mata','papafigo'=>'noite','bradador'=>'vento','alamoa'=>'noite','quibungo'=>'mata','labatut'=>'noite','norato'=>'agua','paimato'=>'mata','ceuci'=>'noite','flechas'=>'vento','bolafogo'=>'fogo','veneno'=>'mata','cura'=>'mata','geada'=>'agua','tornado'=>'vento','raio'=>'vento','oca'=>'mata','totem'=>'mata','ninho'=>'vento','tapera'=>'noite','cabriola'=>'mata','tuiuiu'=>'vento','fogofatuo'=>'fogo','caboclo'=>'agua','maocabelo'=>'noite','cachorropadre'=>'noite','pegarrafa'=>'mata','bichopapao'=>'noite','comadre'=>'mata','joaogrilo'=>'mata','bemtevi'=>'vento','boicara'=>'mata','pernacabeluda'=>'noite','catirina'=>'mata','malasartes'=>'mata','porca'=>'mata','loira'=>'noite','mboitui'=>'mata','urutau'=>'noite','jasy'=>'mata','urubu'=>'vento','minhocao'=>'mata','sucuriju'=>'agua','teju'=>'mata','macunaima'=>'mata','almapenada'=>'noite','coruja'=>'noite','naia'=>'agua','pirarucu'=>'agua','ruda'=>'fogo','vitoria'=>'agua','pegadas'=>'mata','pororoca'=>'agua','farinha'=>'mata','cupinzeiro'=>'mata'];
// Pares do Elo de Dupla (a mesma tabela LORE do jogo, usada aqui só para conferir a missão no 2v2)
const LORE_SV = ['curupira'=>['caipora'],'caipora'=>['curupira'],'comadre'=>['caipora','curupira'],'saci'=>['negrinho','matinta'],'negrinho'=>['saci'],'matinta'=>['saci'],'iara'=>['boto','yacu'],'boto'=>['iara'],'yacu'=>['iara'],'lobis'=>['mula'],'mula'=>['lobis'],'boitata'=>['mapin'],'mapin'=>['boitata'],'pernacabeluda'=>['loira'],'loira'=>['pernacabeluda'],'tupa'=>['jaci','guaraci'],'jaci'=>['tupa','guaraci'],'guaraci'=>['tupa','jaci'],'uirapuru'=>['naia'],'naia'=>['uirapuru'],'boicara'=>['catirina'],'catirina'=>['boicara'],'malasartes'=>['joaogrilo'],'joaogrilo'=>['malasartes'],'minhocao'=>['sucuriju'],'sucuriju'=>['minhocao','boiuna'],'boiuna'=>['sucuriju']];
const ELO_WINDOW = 900; // ticks (30 s a 30/s): o parceiro precisa ter baixado o par da lenda até 30 s antes
// Sorteio por faixa: [tipo, metas possíveis]. Faixa 0 = fácil · 1 = média · 2 = desafio
const MIS_POOLS = [
    [['play', [3, 4, 5]], ['win', [1, 2]]],
    [['elem', [3, 4, 5]], ['towers', [3, 4, 5]], ['pvp', [1, 2]]],
    [['elo', [1, 2]], ['story', [1, 2]], ['storywin', [1]], ['win2v2', [1]]],
];
function elem_of_card($id) { // elemento de uma carta (as criaturas da Oficina herdam o da carta-base)
    static $cc = [];
    $id = (string)$id;
    if (isset(ELEM_OF[$id])) return ELEM_OF[$id];
    if (!preg_match('/^x\d+_\d+$/', $id)) return null;
    if (!array_key_exists($id, $cc)) { $b = q("SELECT base FROM custom_cards WHERE id=?", [$id])->fetchColumn(); $cc[$id] = ($b && isset(ELEM_OF[$b])) ? ELEM_OF[$b] : null; }
    return $cc[$id];
}
function is_card_play($c) { $c = (string)$c; return isset(RARITY[$c]) || (bool)preg_match('/^x\d+_\d+$/', $c); } // jogada de carta (ignora ervas e emotes)
function mis_elem_counts($cards) { $o = []; foreach ($cards as $c) { $e = elem_of_card($c); if ($e) $o[$e] = min(10, ($o[$e] ?? 0) + 1); } return $o; }
function mis_elo_count($mid, $slot) { // vezes que a dupla ativou o Elo no 2v2: o parceiro baixou o par da lenda até 30 s antes (jogadas validadas pelo servidor)
    $tm = $slot <= 2 ? [1, 2] : [3, 4]; $prev = []; $n = 0;
    foreach (q("SELECT side, tick, card FROM moves WHERE match_id=? AND side IN (?,?) ORDER BY tick, id", [$mid, $tm[0], $tm[1]])->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = (string)$r['card']; $t = (int)$r['tick']; $s = (int)$r['side'];
        if (!isset(LORE_SV[$c])) continue;
        foreach ($prev as $p) { if ($p[2] !== $s && $t - $p[1] <= ELO_WINDOW && in_array($p[0], LORE_SV[$c], true)) { $n++; break; } }
        $prev[] = [$c, $t, $s];
    }
    return min(3, $n);
}
function mis_def($kind, $n, $param) { // [texto, moedas, gemas, baú]
    $e = MIS_ELEM[$param] ?? ['', ''];
    switch ($kind) {
        case 'play':     return ["Jogue $n partidas", 100 + 30 * ($n - 3), 0, $n >= 5 ? 'madeira' : ''];
        case 'win':      return [$n == 1 ? 'Vença 1 partida' : "Vença $n partidas", $n == 1 ? 90 : 170, 0, $n >= 2 ? 'madeira' : ''];
        case 'elem':     return ["Jogue $n cartas de {$e[1]} {$e[0]}", 60 + 25 * $n, 0, $n >= 5 ? 'madeira' : ''];
        case 'towers':   return ["Derrube $n torres inimigas", 50 + 25 * $n, 0, ''];
        case 'pvp':      return [$n == 1 ? 'Jogue 1 partida contra outro jogador (1x1)' : "Jogue $n partidas contra outros jogadores (1x1)", $n == 1 ? 150 : 260, 0, $n >= 2 ? 'madeira' : ''];
        case 'elo':      return [$n == 1 ? 'Ative o Elo de Dupla 1 vez no 2v2' : "Ative o Elo de Dupla $n vezes no 2v2", $n == 1 ? 220 : 340, 0, $n >= 2 ? 'prata' : ''];
        case 'story':    return [$n == 1 ? 'Jogue 1 partida do Modo História' : "Jogue $n partidas do Modo História", $n == 1 ? 180 : 300, 0, $n >= 2 ? 'madeira' : ''];
        case 'storywin': return ['Vença 1 capítulo do Modo História', 240, 3, ''];
        case 'win2v2':   return ['Vença 1 partida no 2v2', 260, 0, 'madeira'];
    }
    return ["Jogue $n partidas", 100, 0, ''];
}
function mis_ensure($uid) { // garante que o jogador tem uma série de missões ativa (gera uma nova quando a anterior venceu)
    global $db;
    $now = time();
    if (q("SELECT 1 FROM daily_missions WHERE player_id=? AND expires>? LIMIT 1", [$uid, $now])->fetchColumn()) return;
    if ($db->inTransaction()) return;
    wtx_begin();
    try {
        $row = q("SELECT owned FROM players WHERE id=?" . fu(), [$uid])->fetch(PDO::FETCH_ASSOC); // trava a linha do jogador: duas requisições simultâneas não geram duas séries
        if ($row && !q("SELECT 1 FROM daily_missions WHERE player_id=? AND expires>? LIMIT 1", [$uid, $now])->fetchColumn()) {
            $cnt = []; foreach (J($row['owned'], START) as $k) { $e = elem_of_card($k); if ($e) $cnt[$e] = ($cnt[$e] ?? 0) + 1; }
            q("DELETE FROM daily_missions WHERE player_id=? AND expires<?", [$uid, $now - 2 * 86400]);
            foreach (MIS_POOLS as $slot => $pool) {
                $pk = $pool[array_rand($pool)]; $kind = $pk[0]; $n = $pk[1][array_rand($pk[1])]; $param = '';
                if ($kind === 'elem') { if ($cnt) { $ks = array_keys($cnt); $param = $ks[array_rand($ks)]; } else { $kind = 'towers'; $n = 3; } } // só sorteia elementos que o jogador tem
                [$txt, $rg, $rm, $rch] = mis_def($kind, $n, $param);
                q("INSERT INTO daily_missions(player_id,sid,slot,kind,param,txt,target,progress,rg,rm,rch,claimed,expires) VALUES(?,?,?,?,?,?,?,0,?,?,?,0,?)", [$uid, $now, $slot, $kind, $param, $txt, $n, $rg, $rm, $rch, $now + MIS_TTL]);
            }
        }
        wtx_commit();
    } catch (Exception $e) { wtx_rollback(); throw $e; }
}
function missions($uid) { // lista no mesmo formato de antes (id, t, v, n, g, done) + gm (gemas), ch (baú), rw (texto da recompensa), exp (fim da série)
    mis_ensure($uid);
    $out = [];
    foreach (q("SELECT sid,slot,kind,txt,target,progress,rg,rm,rch,claimed,expires FROM daily_missions WHERE player_id=? AND expires>? ORDER BY slot", [$uid, time()])->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rw = [(int)$r['rg'] . ' moedas']; if ((int)$r['rm'] > 0) $rw[] = (int)$r['rm'] . ' 💎'; if ($r['rch'] !== null && $r['rch'] !== '') $rw[] = 'Baú de ' . $r['rch'];
        $out[] = ['id' => $r['sid'] . '_' . $r['slot'], 't' => $r['txt'], 'k' => $r['kind'], 'v' => min((int)$r['progress'], (int)$r['target']), 'n' => (int)$r['target'], 'g' => (int)$r['rg'], 'gm' => (int)$r['rm'], 'ch' => (string)$r['rch'], 'rw' => implode(' + ', $rw), 'done' => (int)$r['claimed'] === 1, 'exp' => (int)$r['expires']];
    }
    return $out;
}
// Soma progresso às missões ativas. $ev: ['play'=>1, 'win'=>1, 'towers'=>2, 'elem'=>['fogo'=>3], 'pvp'=>1, 'elo'=>1, 'story'=>1, 'storywin'=>1, 'win2v2'=>1].
// Devolve os textos das missões que acabaram de ser concluídas. Nunca interrompe a batalha: qualquer erro é só registrado.
function mission_event($uid, $ev) {
    if (!$ev) return [];
    try {
        mis_ensure($uid); $now = time(); $done = [];
        foreach (q("SELECT sid,slot,kind,param,txt,target,progress FROM daily_missions WHERE player_id=? AND expires>? AND claimed=0 AND progress<target", [$uid, $now])->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $add = $r['kind'] === 'elem' ? (int)($ev['elem'][$r['param']] ?? 0) : (int)($ev[$r['kind']] ?? 0);
            if ($add <= 0) continue;
            q("UPDATE daily_missions SET progress=CASE WHEN progress+?>target THEN target ELSE progress+? END WHERE player_id=? AND sid=? AND slot=? AND claimed=0", [$add, $add, $uid, $r['sid'], $r['slot']]); // incremento atômico (sem perder jogadas simultâneas)
            if ((int)$r['progress'] + $add >= (int)$r['target']) $done[] = $r['txt'];
        }
        return $done;
    } catch (Exception $e) { error_log('Encantados missões: ' . $e->getMessage()); return []; }
}
// ===== PostgreSQL: esquema completo (equivale às migrações 1-15 do SQLite), semente de cartas, backup e restauração =====
function pg_schema_sql() {
    return [
        "CREATE TABLE IF NOT EXISTS players(id SERIAL PRIMARY KEY, name TEXT NOT NULL, pass TEXT, avatar TEXT, title TEXT, trophies INTEGER DEFAULT 0, gold INTEGER DEFAULT 200, wins INTEGER DEFAULT 0, losses INTEGER DEFAULT 0, clan_id INTEGER, gems INTEGER DEFAULT 20, owned TEXT, chests TEXT, best INTEGER DEFAULT 0, streak INTEGER DEFAULT 0, since BIGINT, seen BIGINT DEFAULT 0, dlast TEXT, dstreak INTEGER DEFAULT 0, mday TEXT, mbase TEXT, mdone TEXT, tut INTEGER DEFAULT 0, cxp TEXT, txp INTEGER DEFAULT 0, adm INTEGER DEFAULT 0, items TEXT, eqp TEXT, recov TEXT, badges TEXT, ach TEXT, story INTEGER DEFAULT 0)",
        "CREATE UNIQUE INDEX IF NOT EXISTS players_name_lc ON players(lower(name))", // nome único sem diferenciar maiúsculas (era COLLATE NOCASE)
        "CREATE INDEX IF NOT EXISTS players_clan ON players(clan_id)",
        "CREATE INDEX IF NOT EXISTS players_trophies ON players(trophies DESC)",
        "CREATE TABLE IF NOT EXISTS clans(id SERIAL PRIMARY KEY, name TEXT NOT NULL, descr TEXT, leader_id INTEGER)",
        "CREATE UNIQUE INDEX IF NOT EXISTS clans_name_lc ON clans(lower(name))",
        "CREATE TABLE IF NOT EXISTS clan_msgs(id SERIAL PRIMARY KEY, clan_id INTEGER, player_id INTEGER, name TEXT, avatar TEXT, text TEXT, t BIGINT, img TEXT)",
        "CREATE INDEX IF NOT EXISTS clan_msgs_c ON clan_msgs(clan_id, id)",
        "CREATE TABLE IF NOT EXISTS matches(id SERIAL PRIMARY KEY, host INTEGER, guest INTEGER, status TEXT, created BIGINT, ht INTEGER DEFAULT 0, gt INTEGER DEFAULT 0, hseen BIGINT, gseen BIGINT, result TEXT, invite INTEGER, mode TEXT, code TEXT, diff INTEGER DEFAULT 1, story INTEGER DEFAULT 0)",
        "CREATE INDEX IF NOT EXISTS matches_w ON matches(status, hseen)",
        "CREATE TABLE IF NOT EXISTS friends(a INTEGER, b INTEGER, status TEXT, PRIMARY KEY(a,b))",
        "CREATE TABLE IF NOT EXISTS moves(id SERIAL PRIMARY KEY, match_id INTEGER, side INTEGER, tick INTEGER, card TEXT, x DOUBLE PRECISION, y DOUBLE PRECISION)",
        "CREATE INDEX IF NOT EXISTS moves_m ON moves(match_id, id)",
        "CREATE INDEX IF NOT EXISTS moves_side ON moves(match_id, side, id)",
        "CREATE TABLE IF NOT EXISTS auth_tokens(id SERIAL PRIMARY KEY, player_id INTEGER, hash TEXT UNIQUE, created BIGINT, last BIGINT)",
        "CREATE INDEX IF NOT EXISTS tok_p ON auth_tokens(player_id)",
        "CREATE TABLE IF NOT EXISTS tour_clans(season INTEGER, clan_id INTEGER, name TEXT, t BIGINT, PRIMARY KEY(season,clan_id))",
        "CREATE TABLE IF NOT EXISTS tour_pts(season INTEGER, clan_id INTEGER, day INTEGER, pts INTEGER DEFAULT 0, wins INTEGER DEFAULT 0, PRIMARY KEY(season,clan_id,day))",
        "CREATE TABLE IF NOT EXISTS tour_mvp(season INTEGER, player_id INTEGER, clan_id INTEGER, pts INTEGER DEFAULT 0, PRIMARY KEY(season,player_id))",
        "CREATE TABLE IF NOT EXISTS tour_bracket(season INTEGER, rnd INTEGER, slot INTEGER, a INTEGER, b INTEGER, an TEXT, bn TEXT, ap INTEGER, bp INTEGER, winner INTEGER, PRIMARY KEY(season,rnd,slot))",
        "CREATE TABLE IF NOT EXISTS tour_hall(season INTEGER PRIMARY KEY, clan_id INTEGER, name TEXT, descr TEXT, leader TEXT, members TEXT, runner TEXT, pts INTEGER, t BIGINT)",
        "CREATE TABLE IF NOT EXISTS tour_rew(id SERIAL PRIMARY KEY, season INTEGER, player_id INTEGER, place INTEGER, clan TEXT, data TEXT, claimed INTEGER DEFAULT 0, t BIGINT)",
        "CREATE INDEX IF NOT EXISTS tour_rew_p ON tour_rew(player_id, claimed)",
        "CREATE TABLE IF NOT EXISTS cards(id TEXT PRIMARY KEY, name TEXT, emoji TEXT, kind TEXT DEFAULT 'unit', cost INTEGER, hp INTEGER, dmg INTEGER, spd DOUBLE PRECISION, spd_class INTEGER DEFAULT 1, rng DOUBLE PRECISION, rate DOUBLE PRECISION, ctype INTEGER DEFAULT -1, rarity INTEGER DEFAULT 0, arena INTEGER DEFAULT 0, extra TEXT, active INTEGER DEFAULT 1, ord INTEGER DEFAULT 0)",
        "CREATE TABLE IF NOT EXISTS match_players(match_id INTEGER, slot INTEGER, player_id INTEGER, team INTEGER, tick INTEGER DEFAULT 0, seen BIGINT, dropped INTEGER DEFAULT 0, rep TEXT, rep_t BIGINT, PRIMARY KEY(match_id, slot))",
        "CREATE INDEX IF NOT EXISTS mp_player ON match_players(player_id)",
        "CREATE TABLE IF NOT EXISTS custom_cards(id TEXT PRIMARY KEY, owner INTEGER, name TEXT, emoji TEXT, base TEXT, cost INTEGER, hp INTEGER, dmg INTEGER, spd DOUBLE PRECISION, spd_class INTEGER, rate DOUBLE PRECISION, rng DOUBLE PRECISION, ctype INTEGER, rarity INTEGER, created BIGINT)",
        "CREATE INDEX IF NOT EXISTS cc_owner ON custom_cards(owner)",
        daily_missions_sql(),
        "CREATE INDEX IF NOT EXISTS dm_active ON daily_missions(player_id, expires)",
    ];
}
function daily_missions_sql() { // mesma definição no PostgreSQL e no SQLite
    return "CREATE TABLE IF NOT EXISTS daily_missions(player_id INTEGER NOT NULL, sid BIGINT NOT NULL, slot SMALLINT NOT NULL, kind TEXT NOT NULL, param TEXT, txt TEXT NOT NULL, target INTEGER NOT NULL, progress INTEGER NOT NULL DEFAULT 0, rg INTEGER NOT NULL DEFAULT 0, rm INTEGER NOT NULL DEFAULT 0, rch TEXT, claimed SMALLINT NOT NULL DEFAULT 0, expires BIGINT NOT NULL, PRIMARY KEY(player_id, sid, slot))";
}
function cards_seed() { // grava/atualiza as cartas padrão (upsert; funciona em PostgreSQL e SQLite)
    global $db;
    $seed = json_decode(cards_seed_json(), true) ?: [];
    $ins = $db->prepare("INSERT INTO cards(id,name,emoji,kind,cost,hp,dmg,spd,spd_class,rng,rate,ctype,rarity,arena,extra,active,ord) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?) ON CONFLICT (id) DO UPDATE SET name=EXCLUDED.name, emoji=EXCLUDED.emoji, kind=EXCLUDED.kind, cost=EXCLUDED.cost, hp=EXCLUDED.hp, dmg=EXCLUDED.dmg, spd=EXCLUDED.spd, spd_class=EXCLUDED.spd_class, rng=EXCLUDED.rng, rate=EXCLUDED.rate, ctype=EXCLUDED.ctype, rarity=EXCLUDED.rarity, arena=EXCLUDED.arena, extra=EXCLUDED.extra, active=1, ord=EXCLUDED.ord");
    foreach ($seed as $c) $ins->execute([$c['id'], $c['name'], $c['emoji'], $c['kind'], $c['cost'], $c['hp'], $c['dmg'], $c['spd'], $c['spd_class'], $c['rng'], $c['rate'], $c['ctype'], $c['rarity'], $c['arena'], json_encode($c['extra'] ?: new stdClass(), JSON_UNESCAPED_UNICODE), $c['ord']]);
}
function pg_migrate() { // cria/atualiza o esquema uma única vez (transação + trava: duas requisições simultâneas não colidem)
    global $db, $SKIP, $PGMARK;
    if ($SKIP) return;
    if (dbv() >= PG_SCHEMA) { @touch($PGMARK); return; }
    $db->beginTransaction();
    try {
        $db->query('SELECT pg_advisory_xact_lock(727272)')->closeCursor();
        $db->exec("CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT)");
        if (dbv() >= PG_SCHEMA) { $db->commit(); @touch($PGMARK); return; }
        foreach (pg_schema_sql() as $sql) $db->exec($sql);
        cards_seed();
        dbv_set(PG_SCHEMA);
        $db->commit();
    } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); error_log('Encantados PG schema: ' . $e->getMessage()); throw $e; }
    @touch($PGMARK);
}
const BK_TABLES = ['players', 'clans', 'clan_msgs', 'friends', 'auth_tokens', 'cards', 'custom_cards', 'tour_clans', 'tour_pts', 'tour_mvp', 'tour_bracket', 'tour_hall', 'tour_rew', 'daily_missions']; // o que entra no backup (partidas ao vivo não)
const RS_TABLES = ['players', 'clans', 'clan_msgs', 'friends', 'auth_tokens', 'cards', 'custom_cards', 'tour_clans', 'tour_pts', 'tour_mvp', 'tour_bracket', 'tour_hall', 'tour_rew', 'daily_missions', 'matches', 'match_players', 'moves'];
const SERIAL_TABLES = ['players', 'clans', 'clan_msgs', 'matches', 'moves', 'auth_tokens', 'tour_rew'];
function pg_backup() { // gera um arquivo .db (SQLite) com os dados do PostgreSQL: mesmo formato de antes, serve para admin_restore (e para migrar de/para o SQLite)
    global $db, $DATA;
    if (!extension_loaded('pdo_sqlite')) fail('O backup em arquivo .db precisa da extensão pdo_sqlite do PHP.');
    @set_time_limit(180);
    $tmp = $DATA . '/game.bk-' . bin2hex(random_bytes(6)) . '.db';
    try {
        $lt = new PDO('sqlite:' . $tmp); $lt->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lt->exec('PRAGMA journal_mode=OFF'); $lt->exec('PRAGMA synchronous=OFF');
        foreach (BK_TABLES as $t) {
            $cols = table_cols($t); if (!$cols) continue;
            $cs = '"' . implode('","', $cols) . '"';
            $lt->exec('CREATE TABLE "' . $t . '"(' . $cs . ')');
            $ins = $lt->prepare('INSERT INTO "' . $t . '"(' . $cs . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')');
            $lt->beginTransaction();
            $st = $db->query('SELECT ' . $cs . ' FROM "' . $t . '"');
            while ($r = $st->fetch(PDO::FETCH_NUM)) $ins->execute($r);
            $lt->commit();
        }
        $lt->exec('PRAGMA user_version=14');
        $lt = null;
    } catch (Exception $e) { $lt = null; @unlink($tmp); error_log('Encantados backup: ' . $e->getMessage()); fail('Não foi possível gerar o backup.'); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="encantados-backup-' . date('Y-m-d-His') . '.db"');
    header('Content-Length: ' . filesize($tmp)); header('Cache-Control: no-store');
    readfile($tmp); @unlink($tmp); exit;
}
function pg_val($v, $ty) { // o SQLite aceita qualquer tipo em qualquer coluna; o PostgreSQL não: converte antes de gravar
    if ($v === null) return null;
    if ($ty === 'integer' || $ty === 'bigint' || $ty === 'smallint') { if (is_int($v)) return $v; return $v === '' ? null : (int)round((float)$v); }
    if ($ty === 'double precision' || $ty === 'real' || $ty === 'numeric') return $v === '' ? null : (float)$v;
    return $v;
}
function pg_restore($me) { // recebe um backup .db (SQLite: o game.db antigo também serve) e importa tudo para o PostgreSQL, numa transação só
    global $db, $DATA;
    if (!extension_loaded('pdo_sqlite')) fail('Para restaurar um arquivo .db o PHP precisa da extensão pdo_sqlite.');
    @set_time_limit(300);
    $raw = file_get_contents('php://input');
    if (strlen($raw) < 1024 || strlen($raw) > 60 * 1048576 || substr($raw, 0, 16) !== "SQLite format 3\0") fail('Esse arquivo não é um backup do Encantados.');
    $tmp = $DATA . '/game.up-' . bin2hex(random_bytes(6)) . '.db';
    if (file_put_contents($tmp, $raw) === false) fail('Sem espaço para receber o backup.');
    unset($raw);
    try {
        $lt = new PDO('sqlite:' . $tmp); $lt->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (strtolower((string)$lt->query('PRAGMA quick_check')->fetchColumn()) !== 'ok') throw new Exception('corrompido');
        $bt = $lt->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('players', $bt, true)) throw new Exception('sem jogadores');
        $ty = [];
        foreach ($db->query("SELECT table_name, column_name, data_type FROM information_schema.columns WHERE table_schema=current_schema()")->fetchAll(PDO::FETCH_NUM) as $r) $ty[$r[0]][$r[1]] = $r[2];
        $db->beginTransaction();
        $db->query('SELECT pg_advisory_xact_lock(727272)')->closeCursor();
        foreach (RS_TABLES as $t) {
            $db->exec('DELETE FROM "' . $t . '"');
            if (!in_array($t, $bt, true)) continue;
            $bc = array_column($lt->query('PRAGMA table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $cl = array_values(array_intersect(table_cols($t), $bc)); if (!$cl) continue;
            $n = count($cl); $per = max(1, intdiv(900, $n));
            $cs = '"' . implode('","', $cl) . '"'; $ph = '(' . implode(',', array_fill(0, $n, '?')) . ')';
            $buf = [];
            $flush = function () use (&$buf, $db, $t, $cs, $ph) {
                if (!$buf) return;
                $db->prepare('INSERT INTO "' . $t . '"(' . $cs . ') VALUES ' . implode(',', array_fill(0, count($buf), $ph)))->execute(array_merge(...$buf));
                $buf = [];
            };
            $st = $lt->query('SELECT ' . $cs . ' FROM "' . $t . '"');
            while ($r = $st->fetch(PDO::FETCH_NUM)) {
                foreach ($r as $i => $v) $r[$i] = pg_val($v, $ty[$t][$cl[$i]] ?? 'text');
                $buf[] = array_values($r);
                if (count($buf) >= $per) $flush();
            }
            $flush();
        }
        foreach (SERIAL_TABLES as $t) $db->query("SELECT setval(pg_get_serial_sequence('" . $t . "','id'), COALESCE((SELECT MAX(id) FROM \"" . $t . "\"), 0) + 1, false)")->closeCursor();
        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $lt = null; @unlink($tmp); error_log('Encantados restore: ' . $e->getMessage());
        fail('Backup inválido ou danificado (' . mb_substr($e->getMessage(), 0, 160) . '). Nada foi alterado.');
    }
    $lt = null; @unlink($tmp);
    if (!(int)q("SELECT COUNT(*) FROM cards")->fetchColumn()) cards_seed(); // backups antigos podem não ter o catálogo de cartas
    // o admin continua logado mesmo que o id dele seja outro no backup
    $nid = q("SELECT id FROM players WHERE lower(name)=lower(?)", [$me['name']])->fetchColumn() ?: q("SELECT id FROM players WHERE adm=1 ORDER BY id LIMIT 1")->fetchColumn();
    if (!$nid) $nid = qid("INSERT INTO players(name,pass,avatar,title,owned,chests,since,gold,gems,tut,adm) VALUES('ADMIN X',?,?,?,?,'[]',?,999999,99999,1,1)", [password_hash('@GL123', PASSWORD_DEFAULT), '🛡️', 'Administrador', json_encode(array_keys(RARITY)), time() * 1000]);
    session_regenerate_id(true); $_SESSION['uid'] = (int)$nid;
    out(['ok' => 1, 'tok' => tk_issue((int)$nid), 'players' => (int)q("SELECT COUNT(*) FROM players")->fetchColumn(), 'clans' => (int)q("SELECT COUNT(*) FROM clans")->fetchColumn()]);
}
function pay_once($mid, $uid) { // marca "recompensa paga" (moves side=0) de forma atômica: só uma requisição por jogador/partida consegue pagar
    global $db;
    $db->beginTransaction();
    try {
        pg_lock('paid:' . $mid . ':' . $uid);
        $n = q("INSERT INTO moves(match_id,side,tick,card) SELECT CAST(? AS INTEGER),0,0,CAST(? AS TEXT) WHERE NOT EXISTS (SELECT 1 FROM moves WHERE match_id=? AND side=0 AND card=?)", [$mid, (string)$uid, $mid, (string)$uid])->rowCount();
        $db->commit(); return $n > 0;
    } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
const ONLINE = 90; // segundos sem sinal até contar como offline
const PVP_TICKS = 5400; // 120 s + 60 s de morte súbita a 30 ticks/s
function arenaOf($t) { $a = 0; foreach (ARENA_T as $i => $x) { if ($t >= $x) $a = $i; } return $a; }
const START = ['saci','negrinho','caipora','curupira','iara','uirapuru','mula','boi','flechas'];
const PRICE = [300, 800, 2000, 5000, 12000];   // gemas (caro de propósito)
const DUP = [15, 40, 100, 300, 800];         // ouro por carta repetida
// Loja de moedas (ouro): baús, gemas e carta surpresa
const GOLD_SHOP = ['madeira'=>150, 'prata'=>450, 'ouro'=>1200, 'lendario'=>3000, 'gems10'=>500, 'gems60'=>2500, 'carta'=>900];
const CHESTS = ['madeira'=>[1,[70,25,5,0,0],2], 'prata'=>[2,[48,35,14,3,0],4], 'ouro'=>[3,[28,40,24,7,1],8], 'lendario'=>[4,[10,30,35,20,5],15]]; // cartas, pesos de raridade, máx. gemas

// ===== COFRE DE CONTA =====
// Cada resposta com o jogador logado leva um "cofre" criptografado (AES-256-GCM) com a conta inteira:
// troféus, nome, senha (hash), clã, cartas, moedas, itens, insígnias, amigos...
// O jogo guarda o cofre no aparelho. Se o servidor for reiniciado/atualizado e perder o banco,
// o jogador entra de novo e a conta volta sozinha (ação "restore"). Ninguém consegue ler nem falsificar o cofre sem a chave.
// IMPORTANTE: a chave precisa ser SEMPRE a mesma entre atualizações. Para mais segurança, crie a variável de ambiente SAVE_SECRET.
const SAVE_SECRET_DEFAULT = 'bb79651ef867839bced4fae8500876b010c2f77b1258fe33';
function snap_key() { return hash('sha256', 'encantados-cofre|' . (getenv('SAVE_SECRET') ?: SAVE_SECRET_DEFAULT), true); }
function snap_ok() { return function_exists('openssl_encrypt') && function_exists('gzdeflate'); }
function snap_make($uid) {
    if (!snap_ok()) return null;
    try {
        $p = q("SELECT * FROM players WHERE id=?", [$uid])->fetch(PDO::FETCH_ASSOC); if (!$p) return null;
        $c = $p['clan_id'] ? q("SELECT name,descr,leader_id FROM clans WHERE id=?", [$p['clan_id']])->fetch(PDO::FETCH_ASSOC) : null;
        $fr = q("SELECT p.name FROM friends f JOIN players p ON p.id = (CASE WHEN f.a=? THEN f.b ELSE f.a END) WHERE (f.a=? OR f.b=?) AND f.status='ok'", [$uid, $uid, $uid])->fetchAll(PDO::FETCH_COLUMN);
        unset($p['id'], $p['clan_id'], $p['seen']);
        $d = json_encode(['v' => 1, 't' => time(), 'p' => $p, 'c' => $c ? ['n' => $c['name'], 'd' => (string)$c['descr'], 'l' => (int)$c['leader_id'] === (int)$uid] : null, 'f' => $fr], JSON_UNESCAPED_UNICODE);
        $iv = random_bytes(12); $tag = '';
        $x = openssl_encrypt(gzdeflate($d, 9), 'aes-256-gcm', snap_key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $x === false ? null : rtrim(strtr(base64_encode($iv . $tag . $x), '+/', '-_'), '=');
    } catch (Exception $e) { return null; }
}
function snap_read($s) {
    if (!snap_ok() || !is_string($s) || strlen($s) < 40 || strlen($s) > 200000) return null;
    $b = base64_decode(strtr($s, '-_', '+/'), true); if ($b === false || strlen($b) < 29) return null;
    $x = openssl_decrypt(substr($b, 28), 'aes-256-gcm', snap_key(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    if ($x === false) return null;
    $j = @gzinflate($x); $v = $j === false ? null : json_decode($j, true);
    return (is_array($v) && !empty($v['p']['name'])) ? $v : null;
}
function out($d) {
    if (is_array($d) && !empty($d['user']['id'])) { $sv = snap_make($d['user']['id']); if ($sv) $d['save'] = $sv; }
    $j = json_encode($d, JSON_UNESCAPED_UNICODE);
    if (empty($GLOBALS['HQ'])) { echo $j; exit; }
    ignore_user_abort(true); header('Content-Length: ' . strlen($j)); header('Connection: close'); echo $j;
    while (ob_get_level()) @ob_end_flush(); flush(); if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    hook_flush(); exit;
}
function fail($m) { http_response_code(400); out(['error' => $m]); }
function q($sql, $p = []) { global $db; $s = $db->prepare($sql); $s->execute($p); return $s; }
function J($s, $d) { $v = json_decode($s ?? '', true); return is_array($v) ? $v : $d; }
function sv($id, $f, $v) { q("UPDATE players SET $f=? WHERE id=?", [json_encode(array_values($v)), $id]); }
function rr($w) { $r = mt_rand(1, array_sum($w)); foreach ($w as $i => $x) { if (($r -= $x) <= 0) return $i; } return 0; }
function me() {
    if (empty($_SESSION['uid'])) return null;
    $u = q("SELECT p.id,p.name,p.avatar,p.title,p.trophies,p.gold,p.gems,p.wins,p.losses,p.best,p.streak,p.clan_id,p.owned,p.chests,p.items,p.eqp,p.dlast,p.dstreak,p.tut,p.cxp,p.txp,p.adm,p.story,c.name AS clan
            FROM players p LEFT JOIN clans c ON c.id=p.clan_id WHERE p.id=?", [$_SESSION['uid']])->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    try { q("UPDATE players SET seen=? WHERE id=? AND COALESCE(seen,0)<?", [time(), $u['id'], time() - 5]); } catch (Exception $e) {}
    $u['items'] = J($u['items'], []); $e = J($u['eqp'], []); $u['banner'] = $e['b'] ?? ''; $u['tower'] = $e['t'] ?? ''; unset($u['eqp']);
    $u['freq'] = (int)q("SELECT COUNT(*) FROM friends WHERE b=? AND status='pending'", [$u['id']])->fetchColumn();
    $u['owned'] = J($u['owned'], START); $u['chests'] = J($u['chests'], []); $u['gems'] = (int)$u['gems']; $u['tut'] = (int)$u['tut']; $u['cxp'] = J($u['cxp'], []); $u['txp'] = (int)$u['txp']; $u['story'] = (int)($u['story'] ?? 0); $u['adm'] = (int)$u['adm']; $u['streak'] = (int)$u['streak']; $u['dstreak'] = (int)$u['dstreak']; $d0 = date('Y-m-d'); $u['dready'] = $u['dlast'] !== $d0; if ($u['dlast'] !== $d0 && $u['dlast'] !== date('Y-m-d', strtotime('-1 day'))) $u['dstreak'] = 0; $u['best'] = max((int)$u['best'], (int)$u['trophies']);
    return $u;
}
// Sorteia uma carta ainda não obtida (da arena do jogador). Comuns saem mais.
function grantCard($u) {
    $ua = arenaOf($u['trophies']); $pool = [];
    foreach (RARITY as $k => $r) if (CARD_AR[$k] <= $ua && !in_array($k, $u['owned'])) $pool[$k] = [60, 30, 12, 4, 1][$r];
    if (!$pool) return null;
    $x = mt_rand(1, array_sum($pool));
    foreach ($pool as $k => $w) { if (($x -= $w) <= 0) { $own = $u['owned']; $own[] = $k; q("UPDATE players SET owned=? WHERE id=?", [json_encode($own), $u['id']]); return $k; } }
    return null;
}
function fid($name) { return q("SELECT id FROM players WHERE lower(name)=lower(?)", [trim($name ?? '')])->fetchColumn(); }
function need() { $u = me(); if (!$u) fail('Faça login primeiro.'); return $u; }

// ADMIN X: perfil de administrador (conta do jogo). Criado na primeira requisição; troque a senha pelo jogo ou aqui.
function adm() { $u = me(); if (!$u || !$u['adm']) fail('Acesso negado.'); return $u; }
$adm = $SKIP ? 1 : q("SELECT adm FROM players WHERE lower(name)='admin x'")->fetchColumn();
if ($adm === false) {
    try { q("INSERT INTO players(name,pass,avatar,title,owned,chests,since,gold,gems,tut,adm) VALUES('ADMIN X',?,?,?,?,'[]',?,999999,99999,1,1)",
      [password_hash('@GL123', PASSWORD_DEFAULT), '🛡️', 'Administrador', json_encode(array_keys(RARITY)), time() * 1000]); } catch (Exception $e) { error_log('Encantados ADMIN X: ' . $e->getMessage()); } // (duas requisições simultâneas na 1ª vez: a segunda só ignora)
} elseif (!$adm) {
    q("UPDATE players SET adm=1, tut=1, pass=? WHERE lower(name)='admin x'", [password_hash('@GL123', PASSWORD_DEFAULT)]);
}
function tk_issue($uid) {
    $raw = bin2hex(random_bytes(32));
    q("INSERT INTO auth_tokens(player_id,hash,created,last) VALUES(?,?,?,?)", [$uid, hash('sha256', $raw), time(), time()]);
    q("DELETE FROM auth_tokens WHERE player_id=? AND id NOT IN (SELECT id FROM auth_tokens WHERE player_id=? ORDER BY id DESC LIMIT 6)", [$uid, $uid]);
    if (!headers_sent()) setcookie('enc_tk', $raw, ['expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    return $raw;
}
function tk_raw() { $r = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? ($_COOKIE['enc_tk'] ?? ''); return preg_match('/^[a-f0-9]{64}$/', $r) ? $r : ''; }
function tk_restore() {
    if (!empty($_SESSION['uid'])) return;
    $raw = tk_raw(); if ($raw === '') return;
    $r = q("SELECT id,player_id,last FROM auth_tokens WHERE hash=? AND created>?", [hash('sha256', $raw), time() - 31536000])->fetch(PDO::FETCH_ASSOC);
    if (!$r) return;
    $_SESSION['uid'] = (int)$r['player_id'];
    if (time() - (int)$r['last'] > 3600) q("UPDATE auth_tokens SET last=? WHERE id=?", [time(), $r['id']]);
}
function rc_new($id) { // código de recuperação (guardado só como hash)
    $c = strtoupper(bin2hex(random_bytes(5)));
    q("UPDATE players SET recov=? WHERE id=?", [password_hash($c, PASSWORD_DEFAULT), $id]);
    return substr($c, 0, 5) . '-' . substr($c, 5);
}
// ================= TORNEIO DE CLÃS =================
// Temporadas semanais automáticas. Dias 0-3: classificatória (pontos das batalhas dos membros).
// Dia 4: quartas · dia 5: semifinal · dia 6: grande final (vence quem fizer mais pontos no dia).
// Tudo é resolvido no servidor de forma preguiçosa (na primeira requisição depois de cada fase).
if (!USE_PG && dbv() < 12) { // (era < 10: o banco já estava na versão 11 e as tabelas do torneio nunca eram criadas)
 $db->exec("CREATE TABLE IF NOT EXISTS tour_clans(season INTEGER, clan_id INTEGER, name TEXT, t INTEGER, PRIMARY KEY(season,clan_id))");
 $db->exec("CREATE TABLE IF NOT EXISTS tour_pts(season INTEGER, clan_id INTEGER, day INTEGER, pts INTEGER DEFAULT 0, wins INTEGER DEFAULT 0, PRIMARY KEY(season,clan_id,day))");
 $db->exec("CREATE TABLE IF NOT EXISTS tour_mvp(season INTEGER, player_id INTEGER, clan_id INTEGER, pts INTEGER DEFAULT 0, PRIMARY KEY(season,player_id))");
 $db->exec("CREATE TABLE IF NOT EXISTS tour_bracket(season INTEGER, rnd INTEGER, slot INTEGER, a INTEGER, b INTEGER, an TEXT, bn TEXT, ap INTEGER, bp INTEGER, winner INTEGER, PRIMARY KEY(season,rnd,slot))");
 $db->exec("CREATE TABLE IF NOT EXISTS tour_hall(season INTEGER PRIMARY KEY, clan_id INTEGER, name TEXT, descr TEXT, leader TEXT, members TEXT, runner TEXT, pts INTEGER, t INTEGER)");
 $db->exec("CREATE TABLE IF NOT EXISTS tour_rew(id INTEGER PRIMARY KEY AUTOINCREMENT, season INTEGER, player_id INTEGER, place INTEGER, clan TEXT, data TEXT, claimed INTEGER DEFAULT 0, t INTEGER)");
 try { $db->exec("CREATE INDEX IF NOT EXISTS tour_rew_p ON tour_rew(player_id,claimed)"); } catch (Exception $e) {}
 foreach (["badges TEXT", "ach TEXT"] as $c) { try { $db->exec("ALTER TABLE players ADD COLUMN $c"); } catch (Exception $e) {} }
 $db->exec("PRAGMA user_version=12");
}
// ===== v13: catálogo de cartas no SQLite (com Velocidade) + salas 2v2 de 4 jogadores =====
if (!USE_PG && dbv() < 13) {
 $db->exec("CREATE TABLE IF NOT EXISTS cards(id TEXT PRIMARY KEY, name TEXT, emoji TEXT, kind TEXT DEFAULT 'unit', cost INTEGER, hp INTEGER, dmg INTEGER, spd REAL, spd_class INTEGER DEFAULT 1, rng REAL, rate REAL, ctype INTEGER DEFAULT -1, rarity INTEGER DEFAULT 0, arena INTEGER DEFAULT 0, extra TEXT, active INTEGER DEFAULT 1, ord INTEGER DEFAULT 0)"); // spd_class: 0 rápida, 1 média, 2 lenta
 $db->exec("CREATE TABLE IF NOT EXISTS match_players(match_id INTEGER, slot INTEGER, player_id INTEGER, team INTEGER, tick INTEGER DEFAULT 0, seen INTEGER, dropped INTEGER DEFAULT 0, rep TEXT, rep_t INTEGER, PRIMARY KEY(match_id, slot))");
 try { $db->exec("CREATE INDEX IF NOT EXISTS mp_player ON match_players(player_id)"); $db->exec("CREATE INDEX IF NOT EXISTS moves_side ON moves(match_id, side, id)"); } catch (Exception $e) {}
 $seed = json_decode(cards_seed_json(), true) ?: [];
 $db->beginTransaction();
 try {
  $ins = $db->prepare("INSERT OR REPLACE INTO cards(id,name,emoji,kind,cost,hp,dmg,spd,spd_class,rng,rate,ctype,rarity,arena,extra,active,ord) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)");
  foreach ($seed as $c) $ins->execute([$c['id'], $c['name'], $c['emoji'], $c['kind'], $c['cost'], $c['hp'], $c['dmg'], $c['spd'], $c['spd_class'], $c['rng'], $c['rate'], $c['ctype'], $c['rarity'], $c['arena'], json_encode($c['extra'] ?: new stdClass(), JSON_UNESCAPED_UNICODE), $c['ord']]);
  q("UPDATE players SET owned=? WHERE name='ADMIN X'", [json_encode(array_keys(RARITY))]); // o ADMIN X vê todas as cartas, inclusive as novas
  $db->commit();
 } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); error_log('Encantados cartas: ' . $e->getMessage()); throw $e; }
 $db->exec("PRAGMA user_version=13");
}
// ===== v14: Modo História (progresso), Oficina de Criaturas (cartas personalizadas) =====
if (!USE_PG && dbv() < 14) {
 foreach (["ALTER TABLE players ADD COLUMN story INTEGER DEFAULT 0", "ALTER TABLE matches ADD COLUMN story INTEGER DEFAULT 0"] as $sql) { try { $db->exec($sql); } catch (Exception $e) {} } // se a coluna já existir, segue
 $db->exec("CREATE TABLE IF NOT EXISTS custom_cards(id TEXT PRIMARY KEY, owner INTEGER, name TEXT, emoji TEXT, base TEXT, cost INTEGER, hp INTEGER, dmg INTEGER, spd REAL, spd_class INTEGER, rate REAL, rng REAL, ctype INTEGER, rarity INTEGER, created INTEGER)");
 try { $db->exec("CREATE INDEX IF NOT EXISTS cc_owner ON custom_cards(owner)"); } catch (Exception $e) {}
 $db->exec("PRAGMA user_version=14");
}
// ===== v15 (SQLite): missões diárias automáticas =====
if (!USE_PG && dbv() < 15) {
 $db->exec(daily_missions_sql());
 try { $db->exec("CREATE INDEX IF NOT EXISTS dm_active ON daily_missions(player_id, expires)"); } catch (Exception $e) {}
 $db->exec("PRAGMA user_version=15");
}
const CRAFT_COST = 5000;   // moedas por criatura personalizada registrada
const CRAFT_MAX = 12;      // criaturas personalizadas por jogador
const CRAFT_PCT = 30;      // variação máxima (±%) de vida, dano e velocidade sobre a carta-base
const CRAFT_EMOJI = ['🦊','🐺','🐗','🦌','🐍','🦅','🐊','🐟','🔥','🌊','🌿','🌙','☀️','⭐','💀','👻','🧿','🪶','🍃','⚡','🐆','🦉','🐢','🌺'];
const STORY_REWARD = [[150, 3], [200, 3], [260, 4], [320, 4], [400, 5], [480, 5], [600, 6], [800, 10]]; // [moedas, gemas] por capítulo (1ª vez; repetição paga 1/4 das moedas)
const STORY_DIFF = [0, 0, 1, 1, 1, 2, 2, 2];
// Fase da Lua (0 nova, 1 crescente, 2 cheia, 3 minguante). O SERVIDOR calcula a partir do horário de criação da sala,
// assim todos os jogadores da mesma partida usam exatamente a mesma fase (a simulação é sincronizada, não pode divergir).
function moon_code($ts) { $f = fmod(fmod(($ts / 86400 + 2440587.5 - 2451550.1) / 29.530588853, 1) + 1, 1); return ($f < .0625 || $f >= .9375) ? 0 : (($f >= .4375 && $f < .5625) ? 2 : ($f < .5 ? 1 : 3)); }
function card_ok($id) { return isset(RARITY[$id]) || (preg_match('/^x\d+_\d+$/', (string)$id) && q("SELECT 1 FROM custom_cards WHERE id=?", [$id])->fetchColumn()); }
function craft_power($hp, $d, $rate, $rng, $cost, $cls) { return sqrt($hp * ($d / max(0.2, $rate))) * [1.12, 1, .9][$cls] * ($rng > 30 ? 1.08 : 1) / max(1, $cost); } // poder por mana (mesma fórmula do balanceamento)
// ===== 2v2 · 4 jogadores humanos (mesmo modelo "lockstep" do 1x1: o servidor só retransmite comandos) =====
// Vagas 1-2 = equipe A (azul, lado de baixo) · 3-4 = equipe B (vermelha). Cada jogador tem tick próprio em match_players.
// 2v2: tempo estendido e arena grande. Estes valores precisam bater com AR4 do index.html (time/sd/W/H); o servidor só valida e retransmite (quem simula é o cliente).
const Q4_TIME = 300; const Q4_SD = 120;  // segundos: 5 min de partida + 2 min de prorrogação (1ª coroa vence)
const Q4_TICKS = (Q4_TIME + Q4_SD) * 30; // 12600 ticks a 30/s (limite de ticks aceito por jogador)
const Q4_W = 480; const Q4_H = 680;      // arena 2v2 (jogadas fora dela são cortadas nas bordas)
const Q4_TB = [1, 1.1, 1.2, 1.3, 1.4];   // bônus de troféus por torre inimiga derrubada (0 a 4 — cada equipe tem 4: 2 Reis lado a lado no centro + 2 Princesas, uma por pista)
const Q4_CFG = ['time' => Q4_TIME, 'sd' => Q4_SD, 'ticks' => Q4_TICKS, 'w' => Q4_W, 'h' => Q4_H, 'towers' => 4, 'kings' => 2, 'princess' => 2, 'hp' => ['tower' => 4400, 'king' => 7200]]; // HP dobrado no 2v2 (o cliente lê daqui)
const Q4_LOBBY = 15;   // s sem sinal na sala de espera = o jogador sai da sala
const Q4_DROP = 20;    // s sem sinal em jogo = desconectado (a partida segue sem ele; os outros param de esperar por ele)
function q4_team($slot) { return $slot <= 2 ? 1 : 2; }
function q4_players($mid) {
    $rows = q("SELECT mp.slot, p.name, p.avatar, p.title, p.trophies, p.eqp FROM match_players mp JOIN players p ON p.id = mp.player_id WHERE mp.match_id=? ORDER BY mp.slot", [$mid])->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $e = J($r['eqp'], []); $r['tower'] = $e['t'] ?? ''; $r['banner'] = $e['b'] ?? ''; $r['slot'] = (int)$r['slot']; $r['team'] = q4_team($r['slot']); $r['trophies'] = (int)$r['trophies']; unset($r['eqp']); }
    unset($r);
    return $rows;
}
function q4_alive($mid) { // se uma equipe inteira saiu, a outra vence
    $a = (int)q("SELECT COUNT(*) FROM match_players WHERE match_id=? AND dropped=0 AND slot<=2", [$mid])->fetchColumn();
    $b = (int)q("SELECT COUNT(*) FROM match_players WHERE match_id=? AND dropped=0 AND slot>2", [$mid])->fetchColumn();
    if ($a > 0 && $b > 0) return;
    q("UPDATE matches SET status='done', result=? WHERE id=? AND status='live'", [$a > 0 ? 'A' : ($b > 0 ? 'B' : 'draw'), $mid]);
}
function q4_drop($mid, $now) {
    $n = q("UPDATE match_players SET dropped=1 WHERE match_id=? AND dropped=0 AND seen<?", [$mid, $now - Q4_DROP])->rowCount();
    if ($n) q4_alive($mid);
}
function q4_decide($mid, $now) { // resultado = o que pelo menos 2 jogadores relataram igual (ou, após 10 s, o mais votado); impede um único jogador de forjar a vitória
    if (q("SELECT status FROM matches WHERE id=?", [$mid])->fetchColumn() !== 'live') return;
    $rows = q("SELECT rep, COUNT(*) AS n, MIN(rep_t) AS t0 FROM match_players WHERE match_id=? AND rep IS NOT NULL GROUP BY rep ORDER BY n DESC", [$mid])->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;
    $top = $rows[0]; $tie = isset($rows[1]) && (int)$rows[1]['n'] === (int)$top['n'];
    $first = min(array_map(function ($r) { return (int)$r['t0']; }, $rows));
    $res = null;
    if ((int)$top['n'] >= 2 && !$tie) $res = $top['rep'];
    elseif ($now - $first >= 10) $res = $tie ? 'draw' : $top['rep'];
    if ($res !== null) q("UPDATE matches SET status='done', result=? WHERE id=? AND status='live'", [$res, $mid]);
}
function cards_seed_json() { return <<<'JSONCARDS'
[{"id":"saci","name":"Saci","emoji":"🌪️","kind":"unit","cost":2,"hp":95,"dmg":25,"spd":88,"spd_class":0,"rng":16,"rate":0.7,"ctype":0,"rarity":0,"arena":0,"extra":{},"ord":0},{"id":"negrinho","name":"Negrinho do Pastoreio","emoji":"🕯️","kind":"unit","cost":2,"hp":90,"dmg":18,"spd":60,"spd_class":1,"rng":55,"rate":0.6,"ctype":1,"rarity":0,"arena":0,"extra":{},"ord":1},{"id":"uirapuru","name":"Uirapuru","emoji":"🐦","kind":"unit","cost":2,"hp":65,"dmg":20,"spd":90,"spd_class":0,"rng":50,"rate":0.6,"ctype":1,"rarity":0,"arena":0,"extra":{},"ord":2},{"id":"matinta","name":"Matinta Perera","emoji":"🦉","kind":"unit","cost":2,"hp":80,"dmg":24,"spd":80,"spd_class":0,"rng":45,"rate":0.7,"ctype":1,"rarity":0,"arena":0,"extra":{},"ord":3},{"id":"caipora","name":"Caipora","emoji":"🐗","kind":"unit","cost":3,"hp":315,"dmg":30,"spd":50,"spd_class":2,"rng":16,"rate":1,"ctype":2,"rarity":0,"arena":0,"extra":{},"ord":4},{"id":"curupira","name":"Curupira","emoji":"🔥","kind":"unit","cost":3,"hp":155,"dmg":35,"spd":85,"spd_class":0,"rng":16,"rate":0.9,"ctype":0,"rarity":0,"arena":0,"extra":{},"ord":5},{"id":"iara","name":"Iara","emoji":"🧜‍♀️","kind":"unit","cost":3,"hp":160,"dmg":32,"spd":58,"spd_class":1,"rng":75,"rate":1,"ctype":1,"rarity":0,"arena":0,"extra":{},"ord":6},{"id":"cuia","name":"Cabeça de Cuia","emoji":"🥥","kind":"unit","cost":3,"hp":315,"dmg":30,"spd":46,"spd_class":2,"rng":16,"rate":1,"ctype":2,"rarity":0,"arena":1,"extra":{},"ord":7},{"id":"pisadeira","name":"Pisadeira","emoji":"🦇","kind":"unit","cost":3,"hp":120,"dmg":34,"spd":88,"spd_class":0,"rng":16,"rate":0.8,"ctype":0,"rarity":0,"arena":1,"extra":{},"ord":8},{"id":"anhanga","name":"Anhangá","emoji":"🦌","kind":"unit","cost":3,"hp":240,"dmg":34,"spd":68,"spd_class":1,"rng":16,"rate":0.9,"ctype":0,"rarity":1,"arena":1,"extra":{},"ord":9},{"id":"mula","name":"Mula sem Cabeça","emoji":"🐴","kind":"unit","cost":4,"hp":235,"dmg":55,"spd":80,"spd_class":0,"rng":16,"rate":1.1,"ctype":0,"rarity":1,"arena":0,"extra":{"tw":1},"ord":10},{"id":"boto","name":"Boto Cor-de-Rosa","emoji":"🐬","kind":"unit","cost":4,"hp":250,"dmg":44,"spd":60,"spd_class":1,"rng":60,"rate":0.9,"ctype":1,"rarity":1,"arena":1,"extra":{},"ord":11},{"id":"boi","name":"Boi-Bumbá","emoji":"🐂","kind":"unit","cost":4,"hp":615,"dmg":38,"spd":45,"spd_class":2,"rng":16,"rate":1.1,"ctype":2,"rarity":1,"arena":0,"extra":{"tw":1},"ord":12},{"id":"capelobo","name":"Capelobo","emoji":"🐻","kind":"unit","cost":4,"hp":470,"dmg":46,"spd":48,"spd_class":2,"rng":16,"rate":1,"ctype":2,"rarity":1,"arena":2,"extra":{},"ord":13},{"id":"corpo","name":"Corpo-Seco","emoji":"💀","kind":"unit","cost":4,"hp":350,"dmg":44,"spd":44,"spd_class":2,"rng":60,"rate":1,"ctype":1,"rarity":1,"arena":3,"extra":{},"ord":14},{"id":"curacanga","name":"Curacanga","emoji":"☄️","kind":"unit","cost":4,"hp":200,"dmg":46,"spd":85,"spd_class":0,"rng":16,"rate":0.8,"ctype":0,"rarity":1,"arena":4,"extra":{"tw":1},"ord":15},{"id":"tutu","name":"Tutu Marambá","emoji":"👹","kind":"unit","cost":4,"hp":765,"dmg":42,"spd":42,"spd_class":2,"rng":16,"rate":1.2,"ctype":2,"rarity":2,"arena":2,"extra":{},"ord":16},{"id":"ipupiara","name":"Ipupiara","emoji":"🦭","kind":"unit","cost":4,"hp":340,"dmg":56,"spd":58,"spd_class":1,"rng":16,"rate":0.9,"ctype":0,"rarity":2,"arena":5,"extra":{},"ord":17},{"id":"lobis","name":"Lobisomem","emoji":"🐺","kind":"unit","cost":5,"hp":420,"dmg":70,"spd":72,"spd_class":1,"rng":16,"rate":0.9,"ctype":0,"rarity":2,"arena":2,"extra":{},"ord":18},{"id":"cuca","name":"Cuca","emoji":"🐊","kind":"unit","cost":5,"hp":540,"dmg":58,"spd":40,"spd_class":2,"rng":85,"rate":1.15,"ctype":1,"rarity":2,"arena":3,"extra":{},"ord":19},{"id":"yacu","name":"Yacumama","emoji":"🐋","kind":"unit","cost":5,"hp":825,"dmg":55,"spd":45,"spd_class":2,"rng":16,"rate":1.1,"ctype":2,"rarity":2,"arena":4,"extra":{},"ord":20},{"id":"jurupari","name":"Jurupari","emoji":"🎭","kind":"unit","cost":5,"hp":440,"dmg":70,"spd":46,"spd_class":2,"rng":90,"rate":1.1,"ctype":1,"rarity":2,"arena":5,"extra":{},"ord":21},{"id":"boitata","name":"Boitatá","emoji":"🐍","kind":"unit","cost":5,"hp":800,"dmg":52,"spd":40,"spd_class":2,"rng":16,"rate":1,"ctype":2,"rarity":3,"arena":5,"extra":{},"ord":22},{"id":"boiuna","name":"Boiúna","emoji":"🐉","kind":"unit","cost":5,"hp":420,"dmg":66,"spd":64,"spd_class":1,"rng":80,"rate":1,"ctype":1,"rarity":3,"arena":6,"extra":{},"ord":23},{"id":"maeouro","name":"Mãe do Ouro","emoji":"🌟","kind":"unit","cost":5,"hp":420,"dmg":75,"spd":70,"spd_class":1,"rng":90,"rate":0.9,"ctype":1,"rarity":3,"arena":6,"extra":{},"ord":24},{"id":"onca","name":"Onça Encantada","emoji":"🐆","kind":"unit","cost":5,"hp":330,"dmg":78,"spd":90,"spd_class":0,"rng":16,"rate":0.85,"ctype":0,"rarity":3,"arena":7,"extra":{},"ord":25},{"id":"mapin","name":"Mapinguari","emoji":"🦣","kind":"unit","cost":6,"hp":1060,"dmg":80,"spd":35,"spd_class":2,"rng":16,"rate":1.4,"ctype":2,"rarity":3,"arena":7,"extra":{"tw":1},"ord":26},{"id":"tupa","name":"Tupã","emoji":"⚡","kind":"unit","cost":6,"hp":440,"dmg":110,"spd":84,"spd_class":0,"rng":16,"rate":1,"ctype":0,"rarity":4,"arena":8,"extra":{},"ord":27},{"id":"jaci","name":"Jaci, a Lua","emoji":"🌙","kind":"unit","cost":5,"hp":460,"dmg":80,"spd":70,"spd_class":1,"rng":95,"rate":0.9,"ctype":1,"rarity":4,"arena":8,"extra":{},"ord":28},{"id":"guaraci","name":"Guaraci, o Sol","emoji":"☀️","kind":"unit","cost":6,"hp":1000,"dmg":84,"spd":46,"spd_class":2,"rng":16,"rate":1,"ctype":2,"rarity":4,"arena":9,"extra":{},"ord":29},{"id":"romao","name":"Romãozinho","emoji":"🧒","kind":"unit","cost":2,"hp":85,"dmg":24,"spd":92,"spd_class":0,"rng":16,"rate":0.6,"ctype":0,"rarity":0,"arena":10,"extra":{},"ord":30},{"id":"ahoaho","name":"Ahó-Ahó","emoji":"🐑","kind":"unit","cost":3,"hp":330,"dmg":26,"spd":46,"spd_class":2,"rng":16,"rate":1,"ctype":2,"rarity":0,"arena":10,"extra":{"tw":1},"ord":31},{"id":"papafigo","name":"Papa-Figo","emoji":"🎒","kind":"unit","cost":3,"hp":170,"dmg":38,"spd":60,"spd_class":1,"rng":65,"rate":0.9,"ctype":1,"rarity":1,"arena":11,"extra":{},"ord":32},{"id":"bradador","name":"Bradador","emoji":"📢","kind":"unit","cost":4,"hp":260,"dmg":50,"spd":62,"spd_class":1,"rng":80,"rate":1,"ctype":1,"rarity":1,"arena":12,"extra":{},"ord":33},{"id":"alamoa","name":"Alamoa","emoji":"👻","kind":"unit","cost":4,"hp":250,"dmg":56,"spd":82,"spd_class":0,"rng":16,"rate":0.8,"ctype":0,"rarity":2,"arena":13,"extra":{},"ord":34},{"id":"quibungo","name":"Quibungo","emoji":"🦍","kind":"unit","cost":5,"hp":860,"dmg":52,"spd":44,"spd_class":2,"rng":16,"rate":1.1,"ctype":2,"rarity":2,"arena":14,"extra":{},"ord":35},{"id":"labatut","name":"Labatut","emoji":"👁️","kind":"unit","cost":5,"hp":330,"dmg":74,"spd":80,"spd_class":0,"rng":16,"rate":0.85,"ctype":0,"rarity":3,"arena":15,"extra":{},"ord":36},{"id":"norato","name":"Cobra Norato","emoji":"🐲","kind":"unit","cost":5,"hp":440,"dmg":72,"spd":60,"spd_class":1,"rng":95,"rate":1,"ctype":1,"rarity":3,"arena":16,"extra":{},"ord":37},{"id":"paimato","name":"Pai do Mato","emoji":"🌳","kind":"unit","cost":6,"hp":1180,"dmg":85,"spd":38,"spd_class":2,"rng":16,"rate":1.3,"ctype":2,"rarity":3,"arena":17,"extra":{"tw":1},"ord":38},{"id":"ceuci","name":"Ceuci, Mãe das Estrelas","emoji":"🌌","kind":"unit","cost":6,"hp":550,"dmg":100,"spd":70,"spd_class":1,"rng":100,"rate":0.95,"ctype":1,"rarity":4,"arena":19,"extra":{},"ord":39},{"id":"cabriola","name":"Cabra-Cabriola","emoji":"🐐","kind":"unit","cost":3,"hp":230,"dmg":36,"spd":66,"spd_class":1,"rng":16,"rate":0.9,"ctype":0,"rarity":0,"arena":1,"extra":{},"ord":40},{"id":"tuiuiu","name":"Tuiuiú Encantado","emoji":"🦢","kind":"unit","cost":2,"hp":75,"dmg":22,"spd":85,"spd_class":0,"rng":52,"rate":0.6,"ctype":1,"rarity":0,"arena":2,"extra":{},"ord":41},{"id":"fogofatuo","name":"Fogo-Fátuo","emoji":"🔮","kind":"unit","cost":3,"hp":110,"dmg":34,"spd":95,"spd_class":0,"rng":16,"rate":0.7,"ctype":0,"rarity":0,"arena":3,"extra":{},"ord":42},{"id":"caboclo","name":"Caboclo d'Água","emoji":"🚣","kind":"unit","cost":4,"hp":300,"dmg":44,"spd":60,"spd_class":1,"rng":65,"rate":1,"ctype":1,"rarity":1,"arena":3,"extra":{},"ord":43},{"id":"maocabelo","name":"Mão-de-Cabelo","emoji":"✋","kind":"unit","cost":4,"hp":450,"dmg":50,"spd":50,"spd_class":2,"rng":16,"rate":1,"ctype":2,"rarity":1,"arena":4,"extra":{},"ord":44},{"id":"cachorropadre","name":"Cachorro-do-Padre","emoji":"🐕","kind":"unit","cost":4,"hp":205,"dmg":46,"spd":86,"spd_class":0,"rng":16,"rate":0.8,"ctype":0,"rarity":1,"arena":5,"extra":{},"ord":45},{"id":"pegarrafa","name":"Pé-de-Garrafa","emoji":"🍾","kind":"unit","cost":5,"hp":660,"dmg":60,"spd":44,"spd_class":2,"rng":16,"rate":1.1,"ctype":2,"rarity":2,"arena":5,"extra":{},"ord":46},{"id":"bichopapao","name":"Bicho-Papão","emoji":"👾","kind":"unit","cost":4,"hp":265,"dmg":52,"spd":78,"spd_class":0,"rng":16,"rate":0.85,"ctype":0,"rarity":2,"arena":6,"extra":{},"ord":47},{"id":"comadre","name":"Comadre Fulozinha","emoji":"🌺","kind":"unit","cost":2,"hp":85,"dmg":24,"spd":92,"spd_class":0,"rng":16,"rate":0.65,"ctype":0,"rarity":0,"arena":0,"extra":{},"ord":48},{"id":"joaogrilo","name":"João Grilo","emoji":"🦗","kind":"unit","cost":2,"hp":70,"dmg":19,"spd":86,"spd_class":0,"rng":50,"rate":0.6,"ctype":1,"rarity":0,"arena":1,"extra":{},"ord":49},{"id":"bemtevi","name":"Bem-te-vi Vigia","emoji":"🐤","kind":"unit","cost":2,"hp":60,"dmg":18,"spd":96,"spd_class":0,"rng":45,"rate":0.55,"ctype":1,"rarity":0,"arena":2,"extra":{},"ord":50},{"id":"boicara","name":"Boi da Cara Preta","emoji":"🐃","kind":"unit","cost":3,"hp":330,"dmg":28,"spd":48,"spd_class":2,"rng":16,"rate":1.1,"ctype":2,"rarity":0,"arena":2,"extra":{},"ord":51},{"id":"pernacabeluda","name":"Perna-Cabeluda","emoji":"🦵","kind":"unit","cost":3,"hp":125,"dmg":36,"spd":88,"spd_class":0,"rng":16,"rate":0.8,"ctype":0,"rarity":0,"arena":3,"extra":{},"ord":52},{"id":"catirina","name":"Catirina","emoji":"💃","kind":"unit","cost":3,"hp":200,"dmg":32,"spd":64,"spd_class":1,"rng":16,"rate":0.9,"ctype":0,"rarity":0,"arena":4,"extra":{},"ord":53},{"id":"malasartes","name":"Pedro Malasartes","emoji":"🎩","kind":"unit","cost":4,"hp":200,"dmg":50,"spd":86,"spd_class":0,"rng":16,"rate":0.8,"ctype":0,"rarity":1,"arena":1,"extra":{},"ord":54},{"id":"porca","name":"Porca dos Sete Leitões","emoji":"🐖","kind":"unit","cost":4,"hp":480,"dmg":40,"spd":46,"spd_class":2,"rng":16,"rate":1,"ctype":2,"rarity":1,"arena":3,"extra":{},"ord":55},{"id":"loira","name":"Loira do Banheiro","emoji":"👱‍♀️","kind":"unit","cost":4,"hp":240,"dmg":52,"spd":62,"spd_class":1,"rng":70,"rate":1,"ctype":1,"rarity":1,"arena":4,"extra":{},"ord":56},{"id":"mboitui","name":"Mboi Tu'i","emoji":"🦜","kind":"unit","cost":4,"hp":300,"dmg":46,"spd":64,"spd_class":1,"rng":16,"rate":0.95,"ctype":0,"rarity":1,"arena":5,"extra":{},"ord":57},{"id":"urutau","name":"Urutau Chorão","emoji":"🕊️","kind":"unit","cost":3,"hp":160,"dmg":38,"spd":58,"spd_class":1,"rng":60,"rate":0.9,"ctype":1,"rarity":1,"arena":6,"extra":{},"ord":58},{"id":"jasy","name":"Jasy Jateré","emoji":"👱","kind":"unit","cost":3,"hp":140,"dmg":36,"spd":90,"spd_class":0,"rng":16,"rate":0.7,"ctype":0,"rarity":1,"arena":7,"extra":{},"ord":59},{"id":"urubu","name":"Urubu-Rei","emoji":"🦅","kind":"unit","cost":4,"hp":190,"dmg":40,"spd":82,"spd_class":0,"rng":50,"rate":0.75,"ctype":1,"rarity":1,"arena":8,"extra":{},"ord":60},{"id":"minhocao","name":"Minhocão do Sertão","emoji":"🪱","kind":"unit","cost":5,"hp":800,"dmg":54,"spd":38,"spd_class":2,"rng":16,"rate":1.2,"ctype":2,"rarity":2,"arena":6,"extra":{},"ord":61},{"id":"sucuriju","name":"Sucuriju Gigante","emoji":"🐍","kind":"unit","cost":5,"hp":760,"dmg":62,"spd":42,"spd_class":2,"rng":16,"rate":1.15,"ctype":2,"rarity":2,"arena":9,"extra":{},"ord":62},{"id":"teju","name":"Teju Jaguá","emoji":"🦎","kind":"unit","cost":5,"hp":520,"dmg":58,"spd":60,"spd_class":1,"rng":16,"rate":1,"ctype":2,"rarity":2,"arena":11,"extra":{},"ord":63},{"id":"macunaima","name":"Macunaíma","emoji":"🤸","kind":"unit","cost":5,"hp":300,"dmg":66,"spd":86,"spd_class":0,"rng":16,"rate":0.8,"ctype":0,"rarity":2,"arena":12,"extra":{},"ord":64},{"id":"almapenada","name":"Alma Penada","emoji":"⚰️","kind":"unit","cost":5,"hp":380,"dmg":72,"spd":58,"spd_class":1,"rng":85,"rate":1.1,"ctype":1,"rarity":2,"arena":14,"extra":{},"ord":65},{"id":"coruja","name":"Rasga-Mortalha","emoji":"🦉","kind":"unit","cost":5,"hp":290,"dmg":92,"spd":92,"spd_class":0,"rng":16,"rate":0.85,"ctype":0,"rarity":3,"arena":10,"extra":{},"ord":66},{"id":"naia","name":"Naiá da Vitória-Régia","emoji":"🌸","kind":"unit","cost":5,"hp":420,"dmg":70,"spd":60,"spd_class":1,"rng":90,"rate":1,"ctype":1,"rarity":3,"arena":13,"extra":{},"ord":67},{"id":"pirarucu","name":"Pirarucu Encantado","emoji":"🐟","kind":"unit","cost":6,"hp":1050,"dmg":72,"spd":36,"spd_class":2,"rng":16,"rate":1.2,"ctype":2,"rarity":3,"arena":16,"extra":{},"ord":68},{"id":"ruda","name":"Rudá, Deus do Amor","emoji":"💘","kind":"unit","cost":6,"hp":580,"dmg":100,"spd":62,"spd_class":1,"rng":100,"rate":1,"ctype":1,"rarity":4,"arena":18,"extra":{},"ord":69},{"id":"flechas","name":"Chuva de Flechas","emoji":"🏹","kind":"spell","cost":3,"hp":null,"dmg":120,"spd":null,"spd_class":0,"rng":null,"rate":null,"ctype":-1,"rarity":0,"arena":0,"extra":{"ar":50,"col":14919211},"ord":70},{"id":"bolafogo","name":"Bola de Fogo","emoji":"🔥","kind":"spell","cost":4,"hp":null,"dmg":310,"spd":null,"spd_class":0,"rng":null,"rate":null,"ctype":-1,"rarity":1,"arena":0,"extra":{"ar":38,"col":16738842},"ord":71},{"id":"veneno","name":"Área Venenosa","emoji":"☠️","kind":"spell","cost":4,"hp":null,"dmg":42,"spd":null,"spd_class":2,"rng":null,"rate":null,"ctype":-1,"rarity":1,"arena":1,"extra":{"ar":55,"dur":7,"col":8115050},"ord":72},{"id":"cura","name":"Chá da Benzedeira","emoji":"🍵","kind":"spell","cost":3,"hp":null,"dmg":260,"spd":null,"spd_class":1,"rng":null,"rate":null,"ctype":-1,"rarity":1,"arena":2,"extra":{"ar":60,"col":7008416},"ord":73},{"id":"geada","name":"Névoa Gelada","emoji":"❄️","kind":"spell","cost":3,"hp":null,"dmg":50,"spd":null,"spd_class":1,"rng":null,"rate":null,"ctype":-1,"rarity":2,"arena":3,"extra":{"ar":55,"dur":3.5,"col":10152191},"ord":74},{"id":"tornado","name":"Redemoinho do Saci","emoji":"🌀","kind":"spell","cost":3,"hp":null,"dmg":90,"spd":null,"spd_class":1,"rng":null,"rate":null,"ctype":-1,"rarity":2,"arena":4,"extra":{"ar":70,"dur":1.8,"col":13684991},"ord":75},{"id":"raio","name":"Fúria de Tupã","emoji":"🌩️","kind":"spell","cost":6,"hp":null,"dmg":680,"spd":null,"spd_class":0,"rng":null,"rate":null,"ctype":-1,"rarity":3,"arena":5,"extra":{"ar":34,"col":16777062},"ord":76},{"id":"vitoria","name":"Lago da Vitória-Régia","emoji":"💧","kind":"spell","cost":4,"hp":null,"dmg":38,"spd":null,"spd_class":2,"rng":null,"rate":null,"ctype":-1,"rarity":1,"arena":2,"extra":{"ar":55,"dur":6,"col":7008480},"ord":77},{"id":"pegadas","name":"Pegadas do Curupira","emoji":"👣","kind":"spell","cost":3,"hp":null,"dmg":20,"spd":null,"spd_class":1,"rng":null,"rate":null,"ctype":-1,"rarity":2,"arena":7,"extra":{"ar":60,"dur":3,"col":11921514},"ord":78},{"id":"pororoca","name":"Pororoca","emoji":"🌊","kind":"spell","cost":4,"hp":null,"dmg":80,"spd":null,"spd_class":0,"rng":null,"rate":null,"ctype":-1,"rarity":2,"arena":11,"extra":{"ar":65,"col":4892927},"ord":79},{"id":"oca","name":"Oca dos Guerreiros","emoji":"🛖","kind":"build","cost":5,"hp":700,"dmg":null,"spd":null,"spd_class":1,"rng":null,"rate":null,"ctype":-1,"rarity":1,"arena":1,"extra":{"life":40,"spw":"tupi","si":5,"sn":2},"ord":80},{"id":"totem","name":"Totem Guardião","emoji":"🗿","kind":"build","cost":4,"hp":800,"dmg":42,"spd":null,"spd_class":0,"rng":110,"rate":0.9,"ctype":-1,"rarity":1,"arena":2,"extra":{"life":35},"ord":81},{"id":"ninho","name":"Ninho de Marimbondos","emoji":"🍯","kind":"build","cost":4,"hp":420,"dmg":null,"spd":null,"spd_class":2,"rng":null,"rate":null,"ctype":-1,"rarity":2,"arena":3,"extra":{"life":30,"spw":"abelha","si":6,"sn":3},"ord":82},{"id":"tapera","name":"Tapera Assombrada","emoji":"🏚️","kind":"build","cost":6,"hp":900,"dmg":null,"spd":null,"spd_class":0,"rng":null,"rate":null,"ctype":-1,"rarity":2,"arena":6,"extra":{"life":45,"spw":"visagem","si":4.5,"sn":2},"ord":83},{"id":"farinha","name":"Casa de Farinha","emoji":"🌾","kind":"build","cost":5,"hp":650,"dmg":null,"spd":null,"spd_class":1,"rng":null,"rate":null,"ctype":-1,"rarity":1,"arena":5,"extra":{"life":40,"spw":"curumim","si":4,"sn":2},"ord":84},{"id":"cupinzeiro","name":"Cupinzeiro Guardião","emoji":"🐜","kind":"build","cost":4,"hp":900,"dmg":36,"spd":null,"spd_class":0,"rng":100,"rate":0.8,"ctype":-1,"rarity":2,"arena":9,"extra":{"life":38},"ord":85}]
JSONCARDS;
}
const T_EPOCH = 1794020400; // 07/11/2026 00:00 (horário de Brasília): início da Temporada 1
const T_DAY = 86400; const T_LEN = 7; const T_QDAYS = 4;
const T_ROUNDS = ['Quartas de final', 'Semifinal', 'Grande Final'];
// Recompensas por colocação (cada membro do clã recebe). myth = carta mítica inédita.
const T_REW = [
  1 => ['n' => 'Campeão', 'gems' => 500, 'gold' => 20000, 'myth' => 1, 'banner' => 'b:tc_campeao', 'ins' => 'campeao'],
  2 => ['n' => 'Vice-campeão', 'gems' => 250, 'gold' => 10000, 'myth' => 1, 'banner' => 'b:tc_vice', 'ins' => 'vice'],
  3 => ['n' => 'Semifinalista', 'gems' => 120, 'gold' => 5000, 'myth' => 0, 'banner' => 'b:tc_semi', 'ins' => 'semi'],
  5 => ['n' => 'Quartas de final', 'gems' => 50, 'gold' => 2000, 'myth' => 0, 'banner' => '', 'ins' => 'quartas'],
  9 => ['n' => 'Participante', 'gems' => 10, 'gold' => 500, 'myth' => 0, 'banner' => '', 'ins' => 'participante'],
];
function t_now() { $e = max(0, time() - T_EPOCH); return [intdiv($e, T_DAY * T_LEN) + 1, intdiv($e % (T_DAY * T_LEN), T_DAY)]; }
function t_start($s) { return T_EPOCH + ($s - 1) * T_DAY * T_LEN; }
function t_qual($s, $lim = 100) {
  return q("SELECT tc.clan_id, COALESCE(c.name, tc.name) AS name, c.descr,
     COALESCE((SELECT SUM(pts) FROM tour_pts WHERE season=tc.season AND clan_id=tc.clan_id AND day<?),0) AS pts,
     COALESCE((SELECT SUM(wins) FROM tour_pts WHERE season=tc.season AND clan_id=tc.clan_id AND day<?),0) AS wins,
     COALESCE((SELECT SUM(pts) FROM tour_pts WHERE season=tc.season AND clan_id=tc.clan_id),0) AS total,
     (SELECT COUNT(*) FROM players WHERE clan_id=tc.clan_id) AS members
     FROM tour_clans tc LEFT JOIN clans c ON c.id=tc.clan_id WHERE tc.season=?
     ORDER BY pts DESC, wins DESC, tc.t ASC LIMIT " . (int)$lim, [T_QDAYS, T_QDAYS, $s])->fetchAll(PDO::FETCH_ASSOC);
}
function t_dpts($s, $c, $d) { return (int)q("SELECT pts FROM tour_pts WHERE season=? AND clan_id=? AND day=?", [$s, $c, $d])->fetchColumn(); }
function t_resolve($s) {
  global $db; [$cs, $cd] = t_now();
  if ($s < 1 || $s > $cs) return;
  $day = $s < $cs ? T_LEN : $cd; // dias com índice menor que $day já terminaram
  if ($day < T_QDAYS || q("SELECT 1 FROM tour_hall WHERE season=?", [$s])->fetch()) return;
  $qual = t_qual($s); $qp = []; foreach ($qual as $x) $qp[$x['clan_id']] = (int)$x['pts'];
  if (!q("SELECT 1 FROM tour_bracket WHERE season=? AND rnd=0", [$s])->fetch()) {
    $top = array_slice(array_values(array_filter($qual, function ($x) { return $x['pts'] > 0; })), 0, 8);
    foreach ([[0, 7], [3, 4], [1, 6], [2, 5]] as $i => $p) {
      $A = $top[$p[0]] ?? null; $B = $top[$p[1]] ?? null;
      q("INSERT INTO tour_bracket(season,rnd,slot,a,b,an,bn) VALUES(?,0,?,?,?,?,?) ON CONFLICT DO NOTHING", [$s, $i, $A ? $A['clan_id'] : 0, $B ? $B['clan_id'] : 0, $A ? $A['name'] : '', $B ? $B['name'] : '']);
    }
  }
  for ($r = 0; $r < 3; $r++) {
    $rd = T_QDAYS + $r; if ($day <= $rd) break;
    $rows = q("SELECT * FROM tour_bracket WHERE season=? AND rnd=? ORDER BY slot", [$s, $r])->fetchAll(PDO::FETCH_ASSOC);
    $win = [];
    foreach ($rows as $m) {
      $a = (int)$m['a']; $b = (int)$m['b'];
      if ($m['winner'] === null) {
        $ap = $a ? t_dpts($s, $a, $rd) : 0; $bp = $b ? t_dpts($s, $b, $rd) : 0;
        if (!$a || !$b) $w = $a ?: $b;
        elseif ($ap != $bp) $w = $ap > $bp ? $a : $b;
        else $w = ($qp[$b] ?? 0) > ($qp[$a] ?? 0) ? $b : $a; // empate: melhor campanha na classificatória
        q("UPDATE tour_bracket SET ap=?, bp=?, winner=? WHERE season=? AND rnd=? AND slot=? AND winner IS NULL", [$ap, $bp, $w, $s, $r, $m['slot']]);
        $m['winner'] = $w;
      }
      $w = (int)$m['winner']; $win[] = [$w, $w ? ($w == $a ? $m['an'] : $m['bn']) : ''];
    }
    if ($r < 2) for ($i = 0; $i < intdiv(count($win), 2); $i++)
      q("INSERT INTO tour_bracket(season,rnd,slot,a,b,an,bn) VALUES(?,?,?,?,?,?,?) ON CONFLICT DO NOTHING", [$s, $r + 1, $i, $win[2 * $i][0], $win[2 * $i + 1][0], $win[2 * $i][1], $win[2 * $i + 1][1]]);
  }
  if ($day >= T_LEN) t_finish($s, $qual);
}
function t_finish($s, $qual) {
  global $db;
  $f = q("SELECT * FROM tour_bracket WHERE season=? AND rnd=2 AND slot=0", [$s])->fetch(PDO::FETCH_ASSOC);
  $champ = $f ? (int)$f['winner'] : 0;
  $runner = $f && $champ ? ((int)$f['a'] == $champ ? (int)$f['b'] : (int)$f['a']) : 0;
  $c = $champ ? q("SELECT c.name,c.descr,p.name AS leader FROM clans c LEFT JOIN players p ON p.id=c.leader_id WHERE c.id=?", [$champ])->fetch(PDO::FETCH_ASSOC) : null;
  $cn = $champ ? ($c['name'] ?? ($f['a'] == $champ ? $f['an'] : $f['bn'])) : '';
  $rn = $runner ? ((int)$f['a'] == $runner ? $f['an'] : $f['bn']) : '';
  $mem = $champ ? q("SELECT name,avatar,trophies FROM players WHERE clan_id=? ORDER BY trophies DESC", [$champ])->fetchAll(PDO::FETCH_ASSOC) : [];
  $tot = 0; foreach ($qual as $x) if ($x['clan_id'] == $champ) $tot = (int)$x['total'];
  $db->beginTransaction();
  try {
    $st = q("INSERT INTO tour_hall(season,clan_id,name,descr,leader,members,runner,pts,t) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT DO NOTHING",
      [$s, $champ, $cn, $c['descr'] ?? '', $c['leader'] ?? '', json_encode($mem, JSON_UNESCAPED_UNICODE), $rn, $tot, time()]);
    if (!$st->rowCount()) { $db->rollBack(); return; }
    $place = [];
    if ($champ) $place[$champ] = 1;
    if ($runner) $place[$runner] = 2;
    foreach (q("SELECT rnd,a,b,winner FROM tour_bracket WHERE season=? AND rnd<2", [$s])->fetchAll(PDO::FETCH_ASSOC) as $m)
      foreach ([(int)$m['a'], (int)$m['b']] as $x) if ($x && $x != (int)$m['winner'] && !isset($place[$x])) $place[$x] = $m['rnd'] == 1 ? 3 : 5;
    foreach ($qual as $x) if ($x['total'] > 0 && !isset($place[$x['clan_id']])) $place[$x['clan_id']] = 9;
    foreach ($place as $cid => $p) {
      $nm = q("SELECT name FROM clans WHERE id=?", [$cid])->fetchColumn() ?: '';
      foreach (q("SELECT id FROM players WHERE clan_id=?", [$cid])->fetchAll(PDO::FETCH_COLUMN) as $pid)
        q("INSERT INTO tour_rew(season,player_id,place,clan,data,t) VALUES(?,?,?,?,?,?)", [$s, $pid, $p, $nm, json_encode(T_REW[$p], JSON_UNESCAPED_UNICODE), time()]);
    }
    $db->commit();
  } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); error_log('Encantados torneio: ' . $e->getMessage()); }
}
function t_tick() { [$s] = t_now(); for ($k = max(1, $s - 3); $k <= $s; $k++) t_resolve($k); }
// Soma pontos de torneio ao clã do jogador (chamado ao fim das batalhas). Nunca interrompe a batalha.
function tour_add($u, $pts, $win) {
  try {
    if (empty($u['clan_id']) || $pts <= 0) return 0;
    [$s, $d] = t_now(); $cid = (int)$u['clan_id'];
    if (!q("SELECT 1 FROM tour_clans WHERE season=? AND clan_id=?", [$s, $cid])->fetch()) return 0;
    if ($d >= T_QDAYS) {
      t_resolve($s);
      if (!q("SELECT 1 FROM tour_bracket WHERE season=? AND rnd=? AND (a=? OR b=?) AND a>0 AND b>0 AND winner IS NULL", [$s, $d - T_QDAYS, $cid, $cid])->fetch()) return 0;
    }
    q("INSERT INTO tour_pts(season,clan_id,day,pts,wins) VALUES(?,?,?,0,0) ON CONFLICT DO NOTHING", [$s, $cid, $d]);
    q("UPDATE tour_pts SET pts=pts+?, wins=wins+? WHERE season=? AND clan_id=? AND day=?", [$pts, $win ? 1 : 0, $s, $cid, $d]);
    q("INSERT INTO tour_mvp(season,player_id,clan_id,pts) VALUES(?,?,?,0) ON CONFLICT DO NOTHING", [$s, $u['id'], $cid]);
    q("UPDATE tour_mvp SET pts=pts+?, clan_id=? WHERE season=? AND player_id=?", [$pts, $cid, $s, $u['id']]);
    return $pts;
  } catch (Exception $e) { error_log('Encantados torneio: ' . $e->getMessage()); return 0; }
}
// ================= INSÍGNIAS (conquistas por progresso) =================
// Cada insígnia tem 5 níveis (Bronze → Mítico). Tudo é calculado a partir do progresso real do jogador;
// o nível só vira recompensa quando o jogador resgata (ach_claim).
const ACH_TIERS = ['Bronze', 'Prata', 'Ouro', 'Diamante', 'Mítico'];
const ACH_REW = [[100, 0], [300, 10], [800, 25], [2000, 60], [5000, 150]]; // [moedas, gemas] por nível resgatado
function ach_defs() {
  return [
    ['vit', '⚔️', 'Guerreiro Encantado', 'Vença batalhas', 'wins', [1, 10, 50, 150, 500]],
    ['vet', '🏟️', 'Veterano da Arena', 'Jogue partidas', 'games', [10, 50, 200, 600, 1500]],
    ['tro', '🏆', 'Caminho da Glória', 'Troféus (seu recorde)', 'best', [150, 800, 3000, 9000, 18000]],
    ['seq', '🔥', 'Fogo no Sangue', 'Maior sequência de vitórias', 'streak', [2, 4, 6, 9, 12]],
    ['col', '🃏', 'Colecionador de Lendas', 'Cartas descobertas', 'cards', [12, 24, 36, 50, count(RARITY)]],
    ['len', '✨', 'Caçador de Mitos', 'Cartas lendárias e míticas', 'rare', [1, 3, 6, 9, 13]],
    ['dia', '📅', 'Presença de Ouro', 'Maior ofensiva de dias seguidos', 'dstreak', [3, 7, 14, 30, 60]],
    ['tor', '🏰', 'Fortaleza Viva', 'Nível da sua Torre', 'tower', [2, 4, 6, 8, 10]],
    ['evo', '🌟', 'Mestre das Cartas', 'Maior nível de uma carta', 'cardlv', [2, 4, 6, 8, 10]],
    ['est', '🖼️', 'Estilo Encantado', 'Banners e torres colecionados', 'items', [1, 3, 6, 10, 16]],
    ['cla', '🛡️', 'Espírito de Clã', 'Temporadas do torneio disputadas', 'tparts', [1, 2, 4, 8, 15]],
    ['cam', '👑', 'Coroa dos Campeões', 'Torneios de clã vencidos', 'tchamps', [1, 2, 3, 5, 10]],
  ];
}
function ach_lv($x) { $l = 1; $n = 100; while ($l < 10 && $x >= $n) { $x -= $n; $l++; $n += 60; } return $l; } // mesma curva de XP do jogo
function ach_calc($uid) {
  $r = q("SELECT wins,losses,best,trophies,streak,dstreak,owned,items,txp,cxp,badges,ach FROM players WHERE id=?", [$uid])->fetch(PDO::FETCH_ASSOC);
  if (!$r) return null;
  $owned = J($r['owned'], START); $cx = J($r['cxp'], []); $bd = J($r['badges'], []); $st = J($r['ach'], []);
  if (!isset($st['mx']) || !is_array($st['mx'])) $st['mx'] = [];
  if (!isset($st['cl']) || !is_array($st['cl'])) $st['cl'] = [];
  $rare = 0; foreach ($owned as $k) if ((RARITY[$k] ?? 0) >= 3) $rare++;
  $cl = 0; foreach ($cx as $v) $cl = max($cl, ach_lv((int)$v));
  $tp = 0; $ch = 0; foreach ($bd as $b) { $tp++; if (($b['i'] ?? '') === 'campeao') $ch++; }
  $m = ['wins' => (int)$r['wins'], 'games' => (int)$r['wins'] + (int)$r['losses'], 'best' => max((int)$r['best'], (int)$r['trophies']),
    'streak' => (int)$r['streak'], 'dstreak' => (int)$r['dstreak'], 'cards' => count($owned), 'rare' => $rare, 'tower' => ach_lv((int)$r['txp']),
    'cardlv' => $cl, 'items' => count(J($r['items'], [])), 'tparts' => $tp, 'tchamps' => $ch];
  $dirty = false;
  foreach (['streak', 'dstreak'] as $k) { // sequências zeram no jogo, mas o recorde fica guardado
    $old = (int)($st['mx'][$k] ?? 0);
    if ($m[$k] > $old) { $st['mx'][$k] = $m[$k]; $dirty = true; }
    $m[$k] = max($m[$k], $old);
  }
  $list = []; $score = 0; $ready = 0; $rg = 0; $rm = 0;
  foreach (ach_defs() as $d) {
    $v = $m[$d[4]]; $thr = $d[5]; $t = 0;
    foreach ($thr as $x) if ($v >= $x) $t++;
    $c = min($t, (int)($st['cl'][$d[0]] ?? 0));
    $score += $t; $ready += $t - $c;
    for ($i = $c; $i < $t; $i++) { $rg += ACH_REW[$i][0]; $rm += ACH_REW[$i][1]; }
    $list[] = ['id' => $d[0], 'ic' => $d[1], 'n' => $d[2], 'd' => $d[3], 'v' => $v, 'thr' => $thr, 'tier' => $t, 'claimed' => $c];
  }
  return ['list' => $list, 'score' => $score, 'max' => count($list) * 5, 'ready' => $ready, 'rg' => $rg, 'rm' => $rm, 'st' => $st, 'dirty' => $dirty];
}
function ach_public($a) { unset($a['st'], $a['dirty']); $a['rew'] = ACH_REW; $a['tiers'] = ACH_TIERS; return $a; }
$in = json_decode(file_get_contents('php://input'), true) ?: [];
tk_restore();
$a = $_GET['a'] ?? '';

switch ($a) {
    case 'register':
        $n = trim($in['name'] ?? ''); $p = $in['pass'] ?? '';
        if (!preg_match('/^[\p{L}\p{N}_ ]{3,16}$/u', $n)) fail('Nome: 3 a 16 letras ou números.');
        if (strlen($p) < 4) fail('Senha: mínimo de 4 caracteres.');
        $av = in_array($in['avatar'] ?? '', AVATARS) ? $in['avatar'] : AVATARS[0];
        $ti = in_array($in['title'] ?? '', TITLES) ? $in['title'] : TITLES[0];
        if (q("SELECT 1 FROM players WHERE lower(name)=lower(?)", [$n])->fetch()) fail('Esse nome já está em uso.');
        try { $_SESSION['uid'] = qid("INSERT INTO players(name,pass,avatar,title,owned,chests,since) VALUES(?,?,?,?,?,'[]',?)", [$n, password_hash($p, PASSWORD_DEFAULT), $av, $ti, json_encode(START), time() * 1000]); }
        catch (PDOException $e) { if (in_array((string)$e->getCode(), ['23505', '23000'], true)) fail('Esse nome já está em uso.'); throw $e; } // dois cadastros do mesmo nome ao mesmo tempo
        $rc = rc_new($_SESSION['uid']);
        out(['user' => me(), 'tok' => tk_issue($_SESSION['uid']), 'rc' => $rc]);

    case 'login':
        $r = q("SELECT id,pass FROM players WHERE lower(name)=lower(?)", [trim($in['name'] ?? '')])->fetch(PDO::FETCH_ASSOC);
        if (!$r || !password_verify($in['pass'] ?? '', $r['pass'])) { usleep(400000); fail('Nome ou senha incorretos.'); }
        session_regenerate_id(true); $_SESSION['uid'] = $r['id'];
        out(['user' => me(), 'tok' => tk_issue($r['id'])]);

    case 'restore': // devolve a conta guardada no cofre do aparelho (depois de o servidor perder o banco)
        $sv = snap_read((string)($in['save'] ?? ''));
        if (!$sv) fail('Salvamento inválido.');
        $p = $sv['p']; $nm = trim((string)$p['name']);
        if (!preg_match('/^[\p{L}\p{N}_ ]{1,32}$/u', $nm)) fail('Salvamento inválido.');
        $cols = table_cols('players');
        $f = array_values(array_filter(array_keys($p), function ($k) use ($cols) { return in_array($k, $cols, true) && !in_array($k, ['id', 'clan_id', 'seen', 'name'], true); }));
        $ex = q("SELECT id,since,wins,losses FROM players WHERE lower(name)=lower(?)", [$nm])->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            if ((string)$ex['since'] !== (string)($p['since'] ?? '')) { http_response_code(409); out(['error' => 'O nome "' . $nm . '" foi usado por outro jogador. Peça ao ADMIN X para recuperar sua conta.', 'taken' => 1]); }
            if ((int)$ex['wins'] + (int)$ex['losses'] >= (int)($p['wins'] ?? 0) + (int)($p['losses'] ?? 0)) fail('Conta já está em dia.');
            $id = (int)$ex['id'];
            if ($f) q('UPDATE players SET ' . implode(',', array_map(function ($k) { return '"' . $k . '"=?'; }, $f)) . ' WHERE id=?', array_merge(array_map(function ($k) use ($p) { return $p[$k]; }, $f), [$id]));
        } else {
            $f2 = array_merge(['name'], $f);
            $id = qid('INSERT INTO players("' . implode('","', $f2) . '") VALUES(' . implode(',', array_fill(0, count($f2), '?')) . ')', array_merge([$nm], array_map(function ($k) use ($p) { return $p[$k]; }, $f)));
        }
        $cid = null; $c = $sv['c'] ?? null;
        if ($c && !empty($c['n'])) {
            $cid = q("SELECT id FROM clans WHERE lower(name)=lower(?)", [$c['n']])->fetchColumn();
            if (!$cid) { $cid = qid("INSERT INTO clans(name,descr,leader_id) VALUES(?,?,?)", [$c['n'], (string)($c['d'] ?? ''), $id]); }
            elseif (!empty($c['l'])) q("UPDATE clans SET leader_id=? WHERE id=?", [$id, $cid]);
        }
        q("UPDATE players SET clan_id=? WHERE id=?", [$cid ? (int)$cid : null, $id]);
        foreach (array_slice((array)($sv['f'] ?? []), 0, 100) as $fn) {
            $t = fid((string)$fn); if (!$t || $t == $id) continue;
            if (!q("SELECT 1 FROM friends WHERE (a=? AND b=?) OR (a=? AND b=?)", [$id, $t, $t, $id])->fetch()) q("INSERT INTO friends(a,b,status) VALUES(?,?,'ok')", [$id, $t]);
        }
        session_regenerate_id(true); $_SESSION['uid'] = $id;
        out(['user' => me(), 'tok' => tk_issue($id), 'restored' => 1]);

    case 'logout':
        if (($raw = tk_raw()) !== '') q("DELETE FROM auth_tokens WHERE hash=?", [hash('sha256', $raw)]);
        if (!headers_sent()) setcookie('enc_tk', '', ['expires' => 1, 'path' => '/']);
        session_destroy(); out(['ok' => 1]);
    case 'pass_change':
        $u = need(); $np = $in['new'] ?? '';
        if (!password_verify($in['pass'] ?? '', q("SELECT pass FROM players WHERE id=?", [$u['id']])->fetchColumn())) { usleep(400000); fail('Senha atual incorreta.'); }
        if (strlen($np) < 4) fail('Senha nova: mínimo de 4 caracteres.');
        q("UPDATE players SET pass=? WHERE id=?", [password_hash($np, PASSWORD_DEFAULT), $u['id']]);
        q("DELETE FROM auth_tokens WHERE player_id=?", [$u['id']]); // desconecta os outros aparelhos
        out(['ok' => 1, 'tok' => tk_issue($u['id'])]);
    case 'recov_new':
        $u = need();
        if (!password_verify($in['pass'] ?? '', q("SELECT pass FROM players WHERE id=?", [$u['id']])->fetchColumn())) { usleep(400000); fail('Senha atual incorreta.'); }
        out(['rc' => rc_new($u['id'])]);
    case 'pass_reset':
        $r = q("SELECT id,recov FROM players WHERE lower(name)=lower(?)", [trim($in['name'] ?? '')])->fetch(PDO::FETCH_ASSOC);
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $in['code'] ?? ''));
        if (!$r || !$r['recov'] || !password_verify($code, $r['recov'])) { usleep(700000); fail('Nome ou código de recuperação incorretos.'); }
        if (strlen($in['pass'] ?? '') < 4) fail('Senha: mínimo de 4 caracteres.');
        q("UPDATE players SET pass=? WHERE id=?", [password_hash($in['pass'], PASSWORD_DEFAULT), $r['id']]);
        q("DELETE FROM auth_tokens WHERE player_id=?", [$r['id']]);
        $_SESSION['uid'] = $r['id'];
        out(['user' => me(), 'tok' => tk_issue($r['id']), 'rc' => rc_new($r['id'])]);
    case 'me': out(['user' => me()]);
    case 'ping': // batimento: mantém o jogador online mesmo dentro da batalha
        $u = me(); out(['ok' => $u ? 1 : 0, 'freq' => $u ? $u['freq'] : 0]);
    case 'daily': // recompensa diária sorteada + ofensiva de dias seguidos
        $u = need(); $hoje = date('Y-m-d');
        if ($u['dlast'] === $hoje) fail('Você já pegou a recompensa de hoje. Volte amanhã!');
        $s = $u['dstreak'] + 1; $b = min(7, $s); // bônus cresce até 7 dias
        $tipo = rr([45, 25, 20, 10]); $got = ''; $card = null;
        if ($tipo == 0) { $g = mt_rand(50, 120) * $b; q("UPDATE players SET gold=gold+? WHERE id=?", [$g, $u['id']]); $got = "$g moedas"; }
        elseif ($tipo == 1) { $g = mt_rand(2, 5) * $b; q("UPDATE players SET gems=gems+? WHERE id=?", [$g, $u['id']]); $got = "$g 💎"; }
        elseif ($tipo == 2 && count($u['chests']) < 4) { $k = $b >= 7 ? 'ouro' : ($b >= 3 ? 'prata' : 'madeira'); $ch = $u['chests']; $ch[] = $k; sv($u['id'], 'chests', $ch); $got = "Baú de $k"; }
        elseif ($tipo == 3 && ($card = grantCard($u))) { $got = 'Carta nova!'; }
        else { $g = 100 * $b; q("UPDATE players SET gold=gold+? WHERE id=?", [$g, $u['id']]); $got = "$g moedas"; }
        q("UPDATE players SET dlast=?, dstreak=? WHERE id=?", [$hoje, $s, $u['id']]);
        out(['user' => me(), 'got' => $got, 'card' => $card, 'streak' => $s]);
    case 'missions': $u = need(); $ms = missions($u['id']); out(['m' => $ms, 'exp' => $ms ? $ms[0]['exp'] : 0, 'now' => time()]);
    case 'mission_claim': // resgata a recompensa (moedas, gemas, baú) de uma missão concluída; atômico: não existe resgate duplicado
        $u = need(); $uid = (int)$u['id']; $id = (string)($in['id'] ?? '');
        if (!preg_match('/^(\d{6,12})_([0-2])$/', $id, $mm)) fail('Missão inexistente.');
        $sid = (int)$mm[1]; $slot = (int)$mm[2]; $now = time();
        wtx_begin();
        try {
            $pr = q("SELECT chests FROM players WHERE id=?" . fu(), [$uid])->fetch(PDO::FETCH_ASSOC); // trava o jogador: o 2º clique só é lido depois do 1º terminar
            $r = q("SELECT target,progress,rg,rm,rch,claimed,expires FROM daily_missions WHERE player_id=? AND sid=? AND slot=?", [$uid, $sid, $slot])->fetch(PDO::FETCH_ASSOC);
            if (!$pr || !$r) { wtx_rollback(); fail('Missão inexistente.'); }
            if ((int)$r['claimed']) { wtx_rollback(); fail('Você já pegou essa recompensa.'); }
            if ((int)$r['expires'] <= $now) { wtx_rollback(); fail('Essa missão expirou. Veja as missões novas!'); }
            if ((int)$r['progress'] < (int)$r['target']) { wtx_rollback(); fail('Missão ainda não concluída.'); }
            $ch = J($pr['chests'], []); $rch = (string)$r['rch'];
            if ($rch !== '' && count($ch) >= 4) { wtx_rollback(); fail('Seus 4 espaços de baú estão cheios. Abra um antes de pegar essa recompensa.'); }
            if (!q("UPDATE daily_missions SET claimed=1 WHERE player_id=? AND sid=? AND slot=? AND claimed=0 AND progress>=target AND expires>?", [$uid, $sid, $slot, $now])->rowCount()) { wtx_rollback(); fail('Você já pegou essa recompensa.'); }
            if ($rch !== '' && isset(CHESTS[$rch])) { $ch[] = $rch; q("UPDATE players SET gold=gold+?, gems=gems+?, chests=? WHERE id=?", [(int)$r['rg'], (int)$r['rm'], json_encode(array_values($ch)), $uid]); }
            else q("UPDATE players SET gold=gold+?, gems=gems+? WHERE id=?", [(int)$r['rg'], (int)$r['rm'], $uid]);
            wtx_commit();
        } catch (Exception $e) { wtx_rollback(); throw $e; }
        out(['user' => me(), 'm' => missions($uid), 'g' => (int)$r['rg'], 'gm' => (int)$r['rm'], 'ch' => $rch]);

    case 'gold_buy':
        $u = need(); $it = $in['item'] ?? '';
        if (!isset(GOLD_SHOP[$it])) fail('Item inexistente.');
        $p = GOLD_SHOP[$it];
        if ($u['gold'] < $p) fail('Moedas insuficientes.');
        $got = null;
        if (isset(CHESTS[$it])) {
            if (count($u['chests']) >= 4) fail('Seus 4 espaços de baú estão cheios. Abra um antes.');
            $ch = $u['chests']; $ch[] = $it; sv($u['id'], 'chests', $ch); $got = 'Baú de ' . $it;
        } elseif ($it === 'gems10' || $it === 'gems60') {
            q("UPDATE players SET gems=gems+? WHERE id=?", [$it === 'gems10' ? 10 : 60, $u['id']]); $got = ($it === 'gems10' ? 10 : 60) . ' 💎';
        } else {
            $got = grantCard($u); if (!$got) fail('Você já tem todas as cartas da sua arena!');
        }
        q("UPDATE players SET gold=gold-? WHERE id=?", [$p, $u['id']]);
        out(['user' => me(), 'got' => $got, 'card' => isset(RARITY[$got]) ? $got : null]);

    case 'tut_done':
        $u = need();
        if (!$u['tut']) q("UPDATE players SET tut=1, gold=gold+50 WHERE id=?", [$u['id']]);
        out(['user' => me()]);

    case 'admin_data':
        adm();
        $s = '%' . trim((string)($in['s'] ?? '')) . '%';
        out([
            'stats' => ['players' => (int)q("SELECT COUNT(*) FROM players")->fetchColumn(), 'clans' => (int)q("SELECT COUNT(*) FROM clans")->fetchColumn(),
                'matches' => (int)q("SELECT COUNT(*) FROM matches")->fetchColumn(), 'wins' => (int)q("SELECT COALESCE(SUM(wins),0) FROM players")->fetchColumn(),
                'losses' => (int)q("SELECT COALESCE(SUM(losses),0) FROM players")->fetchColumn()],
            'players' => q("SELECT id,name,avatar,trophies,gold,gems,wins,losses,COALESCE(tut,0) AS tut,COALESCE(txp,0) AS txp FROM players WHERE name " . LK . " ? ORDER BY trophies DESC, id LIMIT 60", [$s])->fetchAll(PDO::FETCH_ASSOC),
            'store' => ['perm' => (USE_PG || DATA_PERM) ? 1 : 0, 'pg' => USE_PG ? 1 : 0, 'cofre' => snap_ok() ? 1 : 0, 'secret' => getenv('SAVE_SECRET') ? 1 : 0],
        ]);

    case 'admin_backup': // baixa o banco inteiro (todos os jogadores, clãs, amigos...) num arquivo .db
        adm();
        if (USE_PG) pg_backup(); // PostgreSQL: gera o .db a partir das tabelas (sai por exit)
        $tmp = DBF . '.bk-' . bin2hex(random_bytes(6));
        try { $db->exec('VACUUM INTO ' . $db->quote($tmp)); }
        catch (Exception $e) { try { $db->exec('PRAGMA wal_checkpoint(FULL)'); } catch (Exception $e2) {} if (!@copy(DBF, $tmp)) fail('Não foi possível gerar o backup.'); }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="encantados-backup-' . date('Y-m-d-His') . '.db"');
        header('Content-Length: ' . filesize($tmp)); header('Cache-Control: no-store');
        readfile($tmp); @unlink($tmp); exit;

    case 'admin_restore': // envia um backup .db e devolve todos os dados dele para o jogo
        $me = adm();
        if (USE_PG) pg_restore($me); // PostgreSQL: importa o .db para o Neon (sai por out/exit)
        $raw = file_get_contents('php://input');
        if (strlen($raw) < 1024 || strlen($raw) > 60 * 1048576 || substr($raw, 0, 16) !== "SQLite format 3\0") fail('Esse arquivo não é um backup do Encantados.');
        $tmp = DBF . '.up-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $raw) === false) fail('Sem espaço para receber o backup.');
        try {
            $db->exec('ATTACH DATABASE ' . $db->quote($tmp) . ' AS bk');
            if (strtolower((string)$db->query('PRAGMA bk.quick_check')->fetchColumn()) !== 'ok') throw new Exception('corrompido');
            $bt = $db->query("SELECT name FROM bk.sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('players', $bt)) throw new Exception('sem jogadores');
            $mt = $db->query("SELECT name FROM main.sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            $db->exec('BEGIN IMMEDIATE');
            foreach ($mt as $t) {
                $db->exec('DELETE FROM main."' . $t . '"');
                if (!in_array($t, $bt)) continue;
                $mc = array_column($db->query('PRAGMA main.table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
                $bc = array_column($db->query('PRAGMA bk.table_info("' . $t . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
                $cl = array_values(array_intersect($mc, $bc)); if (!$cl) continue;
                $cs = '"' . implode('","', $cl) . '"';
                $db->exec('INSERT INTO main."' . $t . '"(' . $cs . ') SELECT ' . $cs . ' FROM bk."' . $t . '"');
            }
            $db->exec('COMMIT');
        } catch (Exception $e) {
            try { $db->exec('ROLLBACK'); } catch (Exception $e2) {}
            try { $db->exec('DETACH DATABASE bk'); } catch (Exception $e2) {}
            @unlink($tmp); fail('Backup inválido ou danificado (' . $e->getMessage() . '). Nada foi alterado.');
        }
        try { $db->exec('DETACH DATABASE bk'); } catch (Exception $e) {} @unlink($tmp);
        // o admin continua logado mesmo que o id dele seja outro no backup
        $nid = q("SELECT id FROM players WHERE lower(name)=lower(?)", [$me['name']])->fetchColumn() ?: q("SELECT id FROM players WHERE adm=1 ORDER BY id LIMIT 1")->fetchColumn();
        if (!$nid) { $nid = qid("INSERT INTO players(name,pass,avatar,title,owned,chests,since,gold,gems,tut,adm) VALUES('ADMIN X',?,?,?,?,'[]',?,999999,99999,1,1)", [password_hash('@GL123', PASSWORD_DEFAULT), '🛡️', 'Administrador', json_encode(array_keys(RARITY)), time() * 1000]); }
        session_regenerate_id(true); $_SESSION['uid'] = (int)$nid;
        out(['ok' => 1, 'tok' => tk_issue((int)$nid), 'players' => (int)q("SELECT COUNT(*) FROM players")->fetchColumn(), 'clans' => (int)q("SELECT COUNT(*) FROM clans")->fetchColumn()]);

    case 'admin_set':
        $me = adm();
        $id = (int)($in['id'] ?? 0);
        if ($op0 = (string)($in['op'] ?? '') and $op0 === 'delete' && $id === (int)$me['id']) fail('Você não pode excluir o próprio perfil admin.');
        if (!q("SELECT 1 FROM players WHERE id=?", [$id])->fetchColumn()) fail('Jogador não encontrado.');
        $op = (string)($in['op'] ?? '');
        if ($op === 'save') {
            foreach (['gold', 'gems', 'trophies', 'txp'] as $f) { if (isset($in[$f])) q("UPDATE players SET $f=? WHERE id=?", [max(0, min(1000000000, (int)$in[$f])), $id]); }
        } elseif ($op === 'tut0') { q("UPDATE players SET tut=0 WHERE id=?", [$id]);
        } elseif ($op === 'cards') { sv($id, 'owned', array_keys(RARITY));
        } elseif ($op === 'xp0') { q("UPDATE players SET cxp=?, txp=0 WHERE id=?", ['{}', $id]);
        } elseif ($op === 'delete') { q("DELETE FROM friends WHERE a=? OR b=?", [$id, $id]); q("DELETE FROM daily_missions WHERE player_id=?", [$id]); q("DELETE FROM players WHERE id=?", [$id]);
        } else fail('Operação inválida.');
        out(['ok' => 1]);

    case 'ranking':
        out(['list' => q("SELECT p.name,p.avatar,p.title,p.trophies,p.wins,c.name AS clan FROM players p
            LEFT JOIN clans c ON c.id=p.clan_id ORDER BY p.trophies DESC, p.wins DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC)]);

    case 'player':
        $p = q("SELECT p.id,p.name,p.avatar,p.title,p.trophies,p.best,p.wins,p.losses,p.owned,p.eqp,p.since,c.name AS clan FROM players p LEFT JOIN clans c ON c.id=p.clan_id WHERE lower(p.name)=lower(?)", [trim($in['name'] ?? '')])->fetch(PDO::FETCH_ASSOC);
        if (!$p) fail('Jogador não encontrado.');
        $p['cards'] = count(J($p['owned'], START)); unset($p['owned']); $e = J($p['eqp'], []); $p['banner'] = $e['b'] ?? ''; unset($p['eqp']); $p['best'] = max((int)$p['best'], (int)$p['trophies']);
        $pa = ach_calc($p['id']); unset($p['id']); $p['ach'] = []; $p['ascore'] = $pa ? $pa['score'] : 0;
        if ($pa) { $e = array_values(array_filter($pa['list'], function ($b) { return $b['tier'] > 0; })); usort($e, function ($x, $y) { return $y['tier'] <=> $x['tier']; });
            foreach (array_slice($e, 0, 6) as $b) $p['ach'][] = ['ic' => $b['ic'], 'n' => $b['n'], 't' => $b['tier']]; }
        out(['p' => $p]);

    case 'profile':
        $u = need();
        if (in_array($in['avatar'] ?? '', AVATARS)) q("UPDATE players SET avatar=? WHERE id=?", [$in['avatar'], $u['id']]);
        if (in_array($in['title'] ?? '', TITLES)) q("UPDATE players SET title=? WHERE id=?", [$in['title'], $u['id']]);
        out(['user' => me()]);

    case 'clans':
        out(['list' => q("SELECT c.id,c.name,c.descr,COUNT(p.id) AS members,COALESCE(SUM(p.trophies),0) AS trophies
            FROM clans c LEFT JOIN players p ON p.clan_id=c.id GROUP BY c.id ORDER BY trophies DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC)]);

    case 'clan_info':
        $u = need();
        if (!$u['clan_id']) out(['clan' => null]);
        $c = q("SELECT id,name,descr,leader_id FROM clans WHERE id=?", [$u['clan_id']])->fetch(PDO::FETCH_ASSOC);
        $c['members'] = q("SELECT id,name,avatar,trophies FROM players WHERE clan_id=? ORDER BY trophies DESC", [$u['clan_id']])->fetchAll(PDO::FETCH_ASSOC);
        out(['clan' => $c]);

    case 'clan_create':
        $u = need();
        if ($u['clan_id']) fail('Saia do clã atual antes de criar outro.');
        if ($u['gold'] < 100) fail('Criar um clã custa 100 de ouro.');
        $n = trim($in['name'] ?? ''); $d = mb_substr(trim($in['descr'] ?? ''), 0, 80);
        if (!preg_match('/^[\p{L}\p{N}_ ]{3,20}$/u', $n)) fail('Nome do clã: 3 a 20 letras ou números.');
        if (q("SELECT 1 FROM clans WHERE lower(name)=lower(?)", [$n])->fetch()) fail('Já existe um clã com esse nome.');
        try { $cid = qid("INSERT INTO clans(name,descr,leader_id) VALUES(?,?,?)", [$n, $d, $u['id']]); }
        catch (PDOException $e) { if (in_array((string)$e->getCode(), ['23505', '23000'], true)) fail('Já existe um clã com esse nome.'); throw $e; }
        q("UPDATE players SET clan_id=?, gold=gold-100 WHERE id=?", [$cid, $u['id']]);
        out(['user' => me()]);

    case 'clan_join':
        $u = need();
        if ($u['clan_id']) fail('Você já está em um clã.');
        $id = (int)($in['id'] ?? 0);
        if (!q("SELECT 1 FROM clans WHERE id=?", [$id])->fetch()) fail('Clã não encontrado.');
        if (q("SELECT COUNT(*) FROM players WHERE clan_id=?", [$id])->fetchColumn() >= 30) fail('Clã lotado (30 membros).');
        q("UPDATE players SET clan_id=? WHERE id=?", [$id, $u['id']]);
        out(['user' => me()]);

    case 'clan_leave':
        $u = need();
        if (!$u['clan_id']) fail('Você não está em um clã.');
        q("UPDATE players SET clan_id=NULL WHERE id=?", [$u['id']]);
        $next = q("SELECT id FROM players WHERE clan_id=? ORDER BY trophies DESC LIMIT 1", [$u['clan_id']])->fetchColumn();
        if ($next) q("UPDATE clans SET leader_id=? WHERE id=? AND leader_id=?", [$next, $u['clan_id'], $u['id']]);
        else q("DELETE FROM clans WHERE id=?", [$u['clan_id']]);
        out(['user' => me()]);

    case 'battle_start':
        $u = need();
        if (!$u['tut']) fail('Conclua o tutorial primeiro.');
        $m = ($in['mode'] ?? '') === 'ranked' ? 'ranked' : 'casual';
        $d = max(0, min(2, (int)($in['diff'] ?? 1)));
        $L = max(0, min(count(RANKED) - 1, (int)($in['league'] ?? 0)));
        if ($m === 'ranked') { if ($u['trophies'] < RANKED[$L][0]) fail(RANKED[$L][1] . ' abre com ' . RANKED[$L][0] . ' troféus.'); $d = 2; }
        $_SESSION['b'] = ['mode' => $m, 'diff' => $d, 't' => time(), 'league' => $L];
        out(['ok' => 1]);

    case 'battle_end':
        $u = need();
        $b = $_SESSION['b'] ?? null; unset($_SESSION['b']);
        if (!$b || time() - $b['t'] < 15) fail('Batalha inválida.');
        $r = $in['r'] ?? ''; $rk = $b['mode'] === 'ranked'; $d = $b['diff']; $L = (int)($b['league'] ?? 0); $w = $r === 'win';
        $win = $rk ? 45 + 5 * $L : [20, 30, 40][$d]; $los = $rk ? 25 : [10, 15, 20][$d];
        $dt = $w ? $win : ($r === 'loss' ? -$los : 0);
        $c = max(0, min(3, (int)($in['c'] ?? 0))); $cb = 0; // torres inimigas derrubadas
        if ($w) { $cb = (int)round($win * [1, 1, 1.25, 1.5][$c]) - $win; $dt += $cb; }
        $g = $w ? ($rk ? 60 + 25 * $L : 30 + 15 * $d) : 8;
        $gm = $w ? ($rk ? 3 + $L : [1, 2, 3][$d]) : 0;
        $streak = $w ? $u['streak'] + 1 : 0; $sb = ($w && $streak >= 3) ? 10 * min($streak, 6) : 0; $g += $sb;
        $chest = null;
        if ($w && mt_rand(1, 100) <= ($rk ? 100 : [50, 70, 90][$d]) && count($u['chests']) < 4) {
            $x = mt_rand(1, 100);
            if ($rk) $chest = $x <= 6 + 4 * $L ? 'lendario' : ($x <= 30 + 5 * $L ? 'ouro' : ($x <= 75 ? 'prata' : 'madeira'));
            else $chest = $d === 2 ? ($x <= 4 && arenaOf($u['trophies']) >= 10 ? 'lendario' : ($x <= 18 ? 'ouro' : ($x <= 60 ? 'prata' : 'madeira'))) : ($d === 1 && $x <= 30 ? 'prata' : 'madeira');
            $ch = $u['chests']; $ch[] = $chest; sv($u['id'], 'chests', $ch);
        }
        // Carta nova: chance por vitória + garantida a cada 5 vitórias
        $card = null; $ms = $w && ($u['wins'] + 1) % 5 === 0;
        if ($w && ($ms || mt_rand(1, 100) <= ($rk ? 45 : [15, 25, 35][$d]))) { $card = grantCard($u); if (!$card) $g += 100; }
        $nt = max(0, $u['trophies'] + $dt);
        q("UPDATE players SET trophies=?, gold=gold+?, gems=gems+?, wins=wins+?, losses=losses+?, streak=?, best=" . MX . "(COALESCE(best,0),?) WHERE id=?",
          [$nt, $g, $gm, $w ? 1 : 0, $r === 'loss' ? 1 : 0, $streak, $nt, $u['id']]);
        // XP: cartas do baralho e torre (só contra a IA)
        $xp = $w ? ($rk ? 60 + 10 * $L : [20, 30, 40][$d]) : ($r === 'loss' ? 8 : 12);
        if ($w) $xp = (int)round($xp * (1 + min(.5, .1 * max(0, $streak - 1))));
        $lv = function ($x) { $l = 1; $n = 100; while ($l < 10 && $x >= $n) { $x -= $n; $l++; $n += 60; } return $l; };
        $cx = $u['cxp']; $used = array_flip(array_map('strval', (array)($in['used'] ?? []))); $up = [];
        foreach (array_slice(array_unique(array_map('strval', (array)($in['deck'] ?? []))), 0, 8) as $id) {
            if (!in_array($id, $u['owned'], true)) continue;
            $gn = (int)round((isset($used[$id]) ? $xp : intdiv($xp, 2)) * [1, 1, 1, .85, .7][min(4, RARITY[$id] ?? 0)]);
            $o = (int)($cx[$id] ?? 0); $cx[$id] = $o + $gn;
            if ($lv($cx[$id]) > $lv($o)) $up[] = ['id' => $id, 'l' => $lv($cx[$id])];
        }
        $tx = (int)$u['txp']; $tup = $lv($tx + $xp) > $lv($tx) ? $lv($tx + $xp) : 0;
        q("UPDATE players SET cxp=?, txp=txp+? WHERE id=?", [json_encode((object)$cx), $xp, $u['id']]);
        tour_add($u, $w ? ($rk ? 6 : 3 + $d) : ($r === 'loss' ? 1 : 0), $w); // Torneio de Clãs
        $uc = []; foreach (array_slice(array_unique(array_map('strval', (array)($in['used'] ?? []))), 0, 12) as $x1) if (in_array($x1, $u['owned'], true)) $uc[] = $x1; // cartas usadas (só as que o jogador possui)
        $mev = []; if ($uc && $r !== 'draw') { $mev = ['play' => 1, 'elem' => mis_elem_counts($uc), 'towers' => $c]; if ($w) $mev['win'] = 1; }
        $mdone = mission_event($u['id'], $mev); // Missões diárias
        out(['user' => me(), 'delta' => $nt - $u['trophies'], 'cw' => $c, 'cb' => $cb, 'gold' => $g, 'gems' => $gm, 'chest' => $chest, 'streak' => $streak, 'sb' => $sb, 'card' => $card, 'milestone' => $ms, 'xp' => $xp, 'up' => $up, 'tup' => $tup, 'mdone' => $mdone]);

    case 'chest_open':
        $u = need(); $i = (int)($in['i'] ?? -1); $ch = $u['chests'];
        if (!isset($ch[$i])) fail('Baú não encontrado.');
        $t = $ch[$i]; array_splice($ch, $i, 1);
        [$n, $w, $gmax] = CHESTS[$t];
        $own = $u['owned']; $got = []; $gold = 10 * $n;
        for ($k = 0; $k < $n; $k++) {
            $rar = rr($w); $ua = arenaOf($u['trophies']);
            do { $pool = array_keys(array_filter(RARITY, fn($q, $k) => $q === $rar && CARD_AR[$k] <= $ua, ARRAY_FILTER_USE_BOTH)); } while (!$pool && $rar-- > 0);
            $id = $pool[array_rand($pool)];
            if (in_array($id, $own)) { $gold += DUP[$rar]; $got[] = ['id' => $id, 'new' => false, 'gold' => DUP[$rar]]; }
            else { $own[] = $id; $got[] = ['id' => $id, 'new' => true]; }
        }
        $gems = mt_rand(0, $gmax);
        q("UPDATE players SET owned=?, chests=?, gold=gold+?, gems=gems+? WHERE id=?", [json_encode($own), json_encode(array_values($ch)), $gold, $gems, $u['id']]);
        out(['user' => me(), 'got' => $got, 'gems' => $gems, 'gold' => $gold]);

    case 'shop_buy':
        $u = need(); $id = $in['id'] ?? '';
        if (!isset(RARITY[$id])) fail('Carta inexistente.');
        if (in_array($id, $u['owned'])) fail('Você já tem essa carta.');
        if (CARD_AR[$id] > arenaOf($u['trophies'])) fail('Essa lenda ainda não apareceu na sua arena.');
        $p = PRICE[RARITY[$id]];
        if ($u['gems'] < $p) fail('Gemas insuficientes.');
        $own = $u['owned']; $own[] = $id;
        q("UPDATE players SET owned=?, gems=gems-? WHERE id=?", [json_encode($own), $p, $u['id']]);
        out(['user' => me()]);

    case 'clan_chat':
        $u = need(); if (!$u['clan_id']) fail('Você não está em um clã.');
        $l = q("SELECT id,name,avatar,text,img,t FROM clan_msgs WHERE clan_id=? AND id>? ORDER BY id DESC LIMIT 50", [$u['clan_id'], (int)($in['since'] ?? 0)])->fetchAll(PDO::FETCH_ASSOC);
        out(['list' => array_reverse($l)]);

    case 'clan_chat_send':
        $u = need(); if (!$u['clan_id']) fail('Você não está em um clã.');
        $t = trim(mb_substr(preg_replace('/\s+/u', ' ', $in['text'] ?? ''), 0, 200));
        $img = $in['img'] ?? null;
        if ($img !== null && $img !== '') {
            if (!is_string($img) || strlen($img) > 400000 || !preg_match('#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $img)) fail('Foto inválida ou muito grande.');
        } else $img = null;
        if ($t === '' && !$img) fail('Mensagem vazia.');
        $last = q("SELECT t FROM clan_msgs WHERE player_id=? ORDER BY id DESC LIMIT 1", [$u['id']])->fetchColumn();
        if ($last && microtime(true) * 1000 - $last < 1500) fail('Calma! Espere um pouquinho para mandar outra.');
        q("INSERT INTO clan_msgs(clan_id,player_id,name,avatar,text,img,t) VALUES(?,?,?,?,?,?,?)", [$u['clan_id'], $u['id'], $u['name'], $u['avatar'], $t, $img, (int)(microtime(true) * 1000)]);
        q("DELETE FROM clan_msgs WHERE clan_id=? AND id NOT IN (SELECT id FROM clan_msgs WHERE clan_id=? ORDER BY id DESC LIMIT 200)", [$u['clan_id'], $u['clan_id']]);
        out(['ok' => 1]);

    // ---------- Jogador contra jogador (ao vivo, lockstep por polling) ----------
    case 'pvp_queue':
        $u = need();
        if (!$u['tut']) fail('Conclua o tutorial primeiro.'); $now = time();
        q("UPDATE matches SET status='gone' WHERE status='wait' AND hseen<?", [$now - 15]);
        q("DELETE FROM matches WHERE status='wait' AND host=?", [$u['id']]);
        $inv = trim($in['invite'] ?? ''); $mode = ($in['mode'] ?? '') === 'duo' ? 'duo' : 'pvp';
        if ($mode === 'duo' && $inv === '' && empty($in['accept']) && trim($in['code'] ?? '') === '') fail('Escolha um amigo ou crie uma sala para jogar em dupla.');
        $diff = max(0, min(2, (int)($in['diff'] ?? 1)));
        $story = ($mode === 'duo') ? (int)($in['story'] ?? 0) : 0;
        if ($story) { if ($story < 1 || $story > count(STORY_REWARD)) fail('Capítulo inválido.'); if ($story > (int)$u['story'] + 1) fail('Conclua o capítulo anterior primeiro.'); $diff = STORY_DIFF[$story - 1]; }
        if (mt_rand(1, 25) === 1) { $old = $now - 86400; q("DELETE FROM moves WHERE match_id IN (SELECT id FROM matches WHERE created<?)", [$old]); q("DELETE FROM matches WHERE created<?", [$old]); }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $in['code'] ?? ''));
        if ($code !== '') {
            $db->beginTransaction(); pg_lock('room:' . $code); // duas pessoas com o mesmo código ao mesmo tempo: uma cria a sala, a outra entra
            $m = q("SELECT id,host,mode FROM matches WHERE status='wait' AND code=? AND host<>? AND hseen>=? ORDER BY id LIMIT 1", [$code, $u['id'], $now - 15])->fetch(PDO::FETCH_ASSOC);
            if ($m && q("UPDATE matches SET guest=?, status='live', gseen=?, hseen=? WHERE id=? AND status='wait'", [$u['id'], $now, $now, $m['id']])->rowCount()) {
                $db->commit(); hook('match.start', ['m' => (int)$m['id'], 'host' => (int)$m['host'], 'guest' => (int)$u['id'], 'room' => $code]);
                out(['m' => (int)$m['id'], 'side' => -1, 'status' => 'live', 'mode' => $m['mode'] ?: 'pvp']); // entra no modo da sala (dupla ou 1x1)
            }
            $wm = qid("INSERT INTO matches(host,status,created,hseen,code,mode,diff,story) VALUES(?,'wait',?,?,?,?,?,?)", [$u['id'], $now, $now, $code, $mode, $diff, $story]);
            $db->commit();
            out(['m' => $wm, 'side' => 1, 'status' => 'wait', 'room' => $code, 'mode' => $mode]);
        }
        if ($inv !== '') {
            $t = q("SELECT id,name,seen FROM players WHERE lower(name)=lower(?)", [$inv])->fetch(PDO::FETCH_ASSOC);
            if (!$t || $t['id'] == $u['id']) fail('Jogador não encontrado.');
            q("UPDATE matches SET status='gone' WHERE status='wait' AND host=? AND invite=?", [$u['id'], $t['id']]);
            $wm = qid("INSERT INTO matches(host,status,created,hseen,invite,mode,diff,story) VALUES(?,'wait',?,?,?,?,?,?)", [$u['id'], $now, $now, $t['id'], $mode, $diff, $story]);
            out(['m' => $wm, 'side' => 1, 'status' => 'wait', 'mode' => $mode, 'online' => (int)$t['seen'] >= $now - ONLINE]);
        }
        $acc = (int)($in['accept'] ?? 0);
        $db->beginTransaction(); if (!$acc) pg_lock('pvp-random'); // fila aleatória serializada: dois jogadores nunca ficam esperando um ao outro
        $m = $acc ? q("SELECT id,host,mode FROM matches WHERE id=? AND status='wait' AND invite=?", [$acc, $u['id']])->fetch(PDO::FETCH_ASSOC)
                  : q("SELECT id,host,mode FROM matches WHERE status='wait' AND invite IS NULL AND code IS NULL AND host<>? ORDER BY id LIMIT 1", [$u['id']])->fetch(PDO::FETCH_ASSOC); // (code IS NULL: a fila aleatória não cai mais em sala privada)
        if ($m && q("UPDATE matches SET guest=?, status='live', gseen=?, hseen=? WHERE id=? AND status='wait'", [$u['id'], $now, $now, $m['id']])->rowCount()) {
            $db->commit(); hook('match.start', ['m' => (int)$m['id'], 'host' => (int)$m['host'], 'guest' => (int)$u['id']]);
            out(['m' => (int)$m['id'], 'side' => -1, 'status' => 'live', 'mode' => $m['mode'] ?: 'pvp']);
        }
        if ($acc) { $db->commit(); fail('Esse convite expirou.'); }
        $wm = qid("INSERT INTO matches(host,status,created,hseen) VALUES(?,'wait',?,?)", [$u['id'], $now, $now]);
        $db->commit();
        out(['m' => $wm, 'side' => 1, 'status' => 'wait']);

    case 'pvp_invites':
        $u = need();
        out(['list' => q("SELECT m.id,m.mode,p.name,p.avatar,p.trophies FROM matches m JOIN players p ON p.id=m.host WHERE m.status='wait' AND m.invite=? AND m.hseen>=?", [$u['id'], time() - 15])->fetchAll(PDO::FETCH_ASSOC)]);

    case 'pvp_decline':
        $u = need();
        q("UPDATE matches SET status='declined' WHERE id=? AND invite=? AND status='wait'", [(int)($in['m'] ?? 0), $u['id']]);
        out(['ok' => 1]);

    case 'pvp_cancel':
        $u = need();
        q("UPDATE matches SET status='gone' WHERE id=? AND host=? AND status='wait'", [(int)($in['m'] ?? 0), $u['id']]);
        out(['ok' => 1]);

    case 'pvp_sync':
        $u = ['id' => (int)($_SESSION['uid'] ?? 0)]; if (!$u['id']) fail('Faça login primeiro.'); $now = time();
        $m = q("SELECT * FROM matches WHERE id=?", [(int)($in['m'] ?? 0)])->fetch(PDO::FETCH_ASSOC);
        if (!$m || ($m['host'] != $u['id'] && $m['guest'] != $u['id'])) fail('Partida não encontrada.');
        $side = $m['host'] == $u['id'] ? 1 : -1; $me = $side == 1 ? 'h' : 'g'; $op = $side == 1 ? 'g' : 'h';
        if ($m['status'] === 'wait') { q("UPDATE matches SET hseen=? WHERE id=?", [$now, $m['id']]); out(['status' => 'wait']); }
        if ($m['status'] === 'live') {
            $tick = min(PVP_TICKS + 200, max((int)$m[$me . 't'], (int)($in['tick'] ?? 0)));
            $db->beginTransaction();
            $emN = 0;
            foreach (array_slice($in['cmds'] ?? [], 0, 20) as $c) {
                $id = $c['card'] ?? ''; $ct = (int)($c['tick'] ?? 0);
                $okE = card_ok($id) || preg_match('/^h:(arruda|guine|alecrim|boldo|mana)$/', (string)$id) || in_array($id, EMOTES) || (preg_match('/^e:@(\w+)$/', $id, $mm) && in_array('m:' . $mm[1], J(q("SELECT items FROM players WHERE id=?", [$u['id']])->fetchColumn(), [])));
                if (!$okE || $ct <= (int)$m[$me . 't']) continue;
                if (strncmp((string)$id, 'e:', 2) === 0 && $emN++ >= 1) continue; // anti-spam de emotes
                q("INSERT INTO moves(match_id,side,tick,card,x,y) VALUES(?,?,?,?,?,?)", [$m['id'], $side, $ct, $id, (float)$c['x'], (float)$c['y']]);
            }
            q("UPDATE matches SET {$me}t=?, {$me}seen=? WHERE id=?", [$tick, $now, $m['id']]);
            $db->commit();
            if ($now - (int)$m[$op . 'seen'] > 20) { // oponente sumiu
                q("UPDATE matches SET status='done', result=? WHERE id=? AND status='live'", [$m['mode'] === 'duo' ? 'draw' : ($side == 1 ? 'host' : 'guest'), $m['id']]);
                $m = q("SELECT * FROM matches WHERE id=?", [$m['id']])->fetch(PDO::FETCH_ASSOC);
            }
        }
        if (!empty($in['wait']) && $m['status'] === 'live') { // comunicação síncrona: segura a resposta até o oponente avançar (máx. 3 s)
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close(); $c0 = (int)($in['ot'] ?? 0); $t0 = microtime(true);
            while (microtime(true) - $t0 < 1.5) {
                $o = q("SELECT {$op}t AS t, status, (SELECT COUNT(*) FROM moves WHERE match_id=? AND side=? AND id>?) AS n FROM matches WHERE id=?", [$m['id'], -$side, (int)($in['since'] ?? 0), $m['id']])->fetch(PDO::FETCH_ASSOC);
                if ((int)$o['t'] > $c0 || (int)$o['n'] > 0 || $o['status'] !== 'live') break;
                usleep(40000);
            }
        }
        $ot = (int)q("SELECT {$op}t FROM matches WHERE id=?", [$m['id']])->fetchColumn(); // lê o tick antes dos lances
        $mv = q("SELECT id,tick,card,x,y FROM moves WHERE match_id=? AND side=? AND id>? ORDER BY id", [$m['id'], -$side, (int)($in['since'] ?? 0)])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mv as &$mq) { $mq['x'] = (float)$mq['x']; $mq['y'] = (float)$mq['y']; } unset($mq); // PostgreSQL devolve decimais como texto: o cliente espera número
        $st = q("SELECT status,result FROM matches WHERE id=?", [$m['id']])->fetch(PDO::FETCH_ASSOC);
        $opp = !empty($in['tick']) ? null : q("SELECT p.name,p.avatar,p.title,p.trophies,p.best,p.wins,p.streak,p.eqp,c.name AS clan FROM players p LEFT JOIN clans c ON c.id=p.clan_id WHERE p.id=?", [$side == 1 ? $m['guest'] : $m['host']])->fetch(PDO::FETCH_ASSOC);
        if ($opp) { $e = J($opp['eqp'], []); $opp['tower'] = $e['t'] ?? ''; $opp['banner'] = $e['b'] ?? ''; unset($opp['eqp']); }
        out(['status' => $st['status'], 'result' => $st['result'], 'otick' => $ot, 'moves' => $mv, 'opp' => $opp, 'side' => $side, 'mode' => $m['mode'] ?: 'pvp', 'diff' => (int)($m['diff'] ?? 1), 'story' => (int)($m['story'] ?? 0), 'moon' => moon_code((int)$m['created'])]);

    case 'pvp_end':
        $u = need();
        $m = q("SELECT * FROM matches WHERE id=?", [(int)($in['m'] ?? 0)])->fetch(PDO::FETCH_ASSOC);
        if (!$m || ($m['host'] != $u['id'] && $m['guest'] != $u['id']) || !$m['guest']) fail('Partida não encontrada.');
        $side = $m['host'] == $u['id'] ? 1 : -1; $me = $side == 1 ? 'host' : 'guest';
        $r = $in['r'] ?? ''; $duo = $m['mode'] === 'duo';
        if ($r === 'quit') $r = $duo ? 'loss' : ($side == 1 ? 'guest' : 'host');
        if (!in_array($r, $duo ? ['win', 'loss', 'draw'] : ['host', 'guest', 'draw'])) fail('Resultado inválido.');
        if ($m['status'] === 'live') { q("UPDATE matches SET status='done', result=? WHERE id=? AND status='live'", [$r, $m['id']]); }
        $m = q("SELECT * FROM matches WHERE id=?", [$m['id']])->fetch(PDO::FETCH_ASSOC);
        $paid = 'paid_' . $m['id'] . '_' . $u['id'];
        $res = $duo ? $m['result'] : ($m['result'] === 'draw' ? 'draw' : ($m['result'] === $me ? 'win' : 'loss'));
        if (!empty($_SESSION[$paid]) || !pay_once($m['id'], $u['id'])) // marca recompensa paga (atômico: dois pedidos simultâneos não pagam duas vezes)
            out(['user' => me(), 'res' => $res, 'delta' => 0, 'gold' => 0]);
        $_SESSION[$paid] = 1; hook('match.end', ['m' => (int)$m['id'], 'result' => $m['result'], 'mode' => $m['mode'] ?: 'pvp']);
        $dt = $res === 'win' ? ($duo ? 30 : 35) : ($res === 'loss' ? ($duo ? -10 : -20) : 0); $g = $res === 'win' ? ($duo ? 45 : 50) : 10; $gm = $res === 'win' ? 2 : 0;
        if ($duo) { $k = [.5, 1, 1.25][max(0, min(2, (int)($m['diff'] ?? 1)))]; $dt = (int)round($dt * $k); $g = (int)round($g * $k); }
        $c = max(0, min(3, (int)($in['c'] ?? 0))); $cb = 0; // torres inimigas derrubadas
        if ($res === 'win') { $cb = (int)round($dt * [1, 1, 1.25, 1.5][$c]) - $dt; $dt += $cb; }
        $card = null; if ($res === 'win' && mt_rand(1, 100) <= 30) $card = grantCard($u);
        $nt = max(0, $u['trophies'] + $dt);
        q("UPDATE players SET trophies=?, gold=gold+?, gems=gems+?, wins=wins+?, losses=losses+?, best=" . MX . "(COALESCE(best,0),?) WHERE id=?",
          [$nt, $g, $gm, $res === 'win' ? 1 : 0, $res === 'loss' ? 1 : 0, $nt, $u['id']]);
        tour_add($u, $res === 'win' ? ($duo ? 8 : 12) : ($res === 'draw' ? 4 : 2), $res === 'win'); // Torneio de Clãs
        $sty = null; // Modo História: só vale com partida de verdade (tempo mínimo e jogadas), para ninguém "relatar" vitória sem jogar
        if ($duo && (int)$m['story'] > 0 && $res === 'win' && time() - (int)$m['created'] >= 45 && (int)q("SELECT COUNT(*) FROM moves WHERE match_id=? AND side>0", [$m['id']])->fetchColumn() >= 4) {
            $ch = (int)$m['story']; $first = (int)$u['story'] < $ch; [$sg, $sgm] = STORY_REWARD[$ch - 1];
            if (!$first) { $sg = intdiv($sg, 4); $sgm = 0; }
            q("UPDATE players SET story=" . MX . "(COALESCE(story,0),?), gold=gold+?, gems=gems+? WHERE id=?", [$ch, $sg, $sgm, $u['id']]);
            $sty = ['ch' => $ch, 'gold' => $sg, 'gems' => $sgm, 'first' => $first];
        }
        $myc = array_values(array_filter(q("SELECT card FROM moves WHERE match_id=? AND side=?", [$m['id'], $side])->fetchAll(PDO::FETCH_COLUMN), 'is_card_play')); // jogadas de carta validadas pelo servidor
        $mev = []; $el = time() - (int)$m['created'];
        if ($el >= 30 && count($myc) >= 2) { // partida de verdade: tempo mínimo e jogadas
            $mev = ['play' => 1, 'elem' => mis_elem_counts($myc), 'towers' => $c];
            if ($res === 'win') $mev['win'] = 1;
            if (!$duo) $mev['pvp'] = 1;
            if ($duo && (int)$m['story'] > 0 && $el >= 45) { $mev['story'] = 1; if ($res === 'win') $mev['storywin'] = 1; }
        }
        $mdone = mission_event($u['id'], $mev); // Missões diárias
        out(['user' => me(), 'res' => $res, 'delta' => $nt - $u['trophies'], 'cw' => $c, 'cb' => $cb, 'gold' => $g, 'gems' => $gm, 'card' => $card, 'story' => $sty, 'mdone' => $mdone]);

    // ---------- Catálogo de cartas (SQLite) ----------
    case 'cards':
        $rows = q("SELECT id,kind,cost AS c,hp,dmg AS d,spd AS s,spd_class AS v,rng AS r,rate AS a,ctype AS t,rarity AS q,arena AS z,extra FROM cards WHERE active=1 ORDER BY ord")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['extra'] = J($r['extra'], []); foreach (['c', 'hp', 'd', 'v', 't', 'q', 'z'] as $k) if ($r[$k] !== null) $r[$k] = (int)$r[$k]; foreach (['s', 'r', 'a'] as $k) if ($r[$k] !== null) $r[$k] = (float)$r[$k]; }
        unset($r);
        $cu = q("SELECT c.id,c.name AS n,c.emoji AS e,c.base,c.cost AS c,c.hp,c.dmg AS d,c.spd AS s,c.rate AS a,c.rng AS r,c.ctype AS t,c.rarity AS q,c.spd_class AS v,p.name AS owner FROM custom_cards c JOIN players p ON p.id=c.owner ORDER BY c.created DESC LIMIT 600")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cu as &$r) { foreach (['c', 'hp', 'd', 't', 'q', 'v'] as $k) $r[$k] = (int)$r[$k]; foreach (['s', 'a', 'r'] as $k) $r[$k] = (float)$r[$k]; } unset($r);
        out(['cards' => $rows, 'customs' => $cu, 'speed' => ['Rápida', 'Média', 'Lenta']]);

    // ---------- 2v2 · 4 jogadores ----------
    case 'q4_queue': // entra numa sala (por código, com equipe preferida) ou na fila aleatória
        $u = need(); $uid = (int)$u['id'];
        if (!$u['tut']) fail('Conclua o tutorial primeiro.');
        $now = time();
        q("DELETE FROM match_players WHERE seen<? AND match_id IN (SELECT id FROM matches WHERE mode='2v2' AND status='wait')", [$now - Q4_LOBBY]);
        q("UPDATE matches SET status='gone' WHERE mode='2v2' AND status='wait' AND id NOT IN (SELECT match_id FROM match_players)");
        q("DELETE FROM match_players WHERE player_id=? AND match_id IN (SELECT id FROM matches WHERE mode='2v2' AND status='wait')", [$uid]);
        if (mt_rand(1, 25) === 1) { $old = $now - 86400; q("DELETE FROM moves WHERE match_id IN (SELECT id FROM matches WHERE created<?)", [$old]); q("DELETE FROM match_players WHERE match_id IN (SELECT id FROM matches WHERE created<?)", [$old]); q("DELETE FROM matches WHERE created<?", [$old]); }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['code'] ?? ''))); $pref = (int)($in['team'] ?? 0);
        $slot = 0; $mid = 0; $live = false;
        for ($try = 0; $try < 3 && !$slot; $try++) {
            $db->beginTransaction();
            try {
                pg_lock('q4:' . ($code === '' ? 'rand' : $code)); // serializa a entrada: não nascem duas salas vazias ao mesmo tempo
                $m = $code !== ''
                    ? q("SELECT id FROM matches WHERE mode='2v2' AND status='wait' AND code=? ORDER BY id LIMIT 1", [$code])->fetch(PDO::FETCH_ASSOC)
                    : q("SELECT m.id FROM matches m WHERE m.mode='2v2' AND m.status='wait' AND m.code IS NULL AND (SELECT COUNT(*) FROM match_players WHERE match_id=m.id)<4 ORDER BY m.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                if ($m) $mid = (int)$m['id'];
                else { $mid = qid("INSERT INTO matches(host,status,created,hseen,code,mode,invite) VALUES(?,'wait',?,?,?,'2v2',-1)", [$uid, $now, $now, $code === '' ? null : $code]); }
                $taken = array_map('intval', q("SELECT slot FROM match_players WHERE match_id=?", [$mid])->fetchAll(PDO::FETCH_COLUMN));
                $pick = 0;
                foreach ($pref === 2 ? [3, 4, 1, 2] : [1, 2, 3, 4] as $sl) { if (!in_array($sl, $taken, true)) { $pick = $sl; break; } }
                if (!$pick) { $db->rollBack(); continue; }
                q("INSERT INTO match_players(match_id,slot,player_id,team,tick,seen,dropped) VALUES(?,?,?,?,0,?,0)", [$mid, $pick, $uid, q4_team($pick), $now]);
                if (count($taken) + 1 >= 4) {
                    q("UPDATE matches SET status='live', hseen=? WHERE id=? AND status='wait'", [$now, $mid]);
                    q("UPDATE match_players SET seen=? WHERE match_id=?", [$now, $mid]); $live = true;
                }
                $db->commit(); $slot = $pick;
            } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); usleep(60000); }
        }
        if (!$slot) fail('Servidor ocupado, tente de novo.');
        if ($live) hook('match.start', ['m' => $mid, 'mode' => '2v2']);
        out(['m' => $mid, 'slot' => $slot, 'status' => $live ? 'live' : 'wait', 'room' => $code ?: null, 'mode' => '2v2', 'cfg' => Q4_CFG]);

    case 'q4_cancel': // sai da sala de espera
        $u = need(); $mid = (int)($in['m'] ?? 0);
        if (q("SELECT status FROM matches WHERE id=? AND mode='2v2'", [$mid])->fetchColumn() === 'wait') {
            q("DELETE FROM match_players WHERE match_id=? AND player_id=?", [$mid, $u['id']]);
            q("UPDATE matches SET status='gone' WHERE id=? AND status='wait' AND NOT EXISTS (SELECT 1 FROM match_players WHERE match_id=?)", [$mid, $mid]);
        }
        out(['ok' => 1]);

    case 'q4_sync': // sala de espera (lista de jogadores) e, em jogo, troca de comandos/ticks entre os 4
        $uid = (int)($_SESSION['uid'] ?? 0); if (!$uid) fail('Faça login primeiro.'); $now = time();
        $mid = (int)($in['m'] ?? 0);
        $m = q("SELECT * FROM matches WHERE id=? AND mode='2v2'", [$mid])->fetch(PDO::FETCH_ASSOC);
        $mp = $m ? q("SELECT * FROM match_players WHERE match_id=? AND player_id=?", [$mid, $uid])->fetch(PDO::FETCH_ASSOC) : null;
        if (!$m || !$mp) fail('Partida não encontrada.');
        $slot = (int)$mp['slot'];
        if ($m['status'] === 'wait') {
            q("UPDATE match_players SET seen=? WHERE match_id=? AND slot=?", [$now, $mid, $slot]);
            q("UPDATE matches SET hseen=? WHERE id=?", [$now, $mid]);
            q("DELETE FROM match_players WHERE match_id=? AND seen<?", [$mid, $now - Q4_LOBBY]);
            $m = q("SELECT * FROM matches WHERE id=?", [$mid])->fetch(PDO::FETCH_ASSOC);
            if ($m['status'] === 'wait') out(['status' => 'wait', 'slot' => $slot, 'players' => q4_players($mid), 'cfg' => Q4_CFG]);
        }
        $since = (int)($in['since'] ?? 0);
        if ($m['status'] === 'live' && !(int)$mp['dropped']) {
            $tick = min(Q4_TICKS + 200, max((int)$mp['tick'], (int)($in['tick'] ?? 0)));
            $db->beginTransaction();
            $emN = 0;
            foreach (array_slice((array)($in['cmds'] ?? []), 0, 24) as $c) {
                if (!is_array($c)) continue;
                $id = (string)($c['card'] ?? ''); $ct = (int)($c['tick'] ?? 0);
                $okE = card_ok($id) || preg_match('/^h:(arruda|guine|alecrim|boldo|mana)$/', (string)$id) || in_array($id, EMOTES, true) || (preg_match('/^e:@(\w+)$/', $id, $mm) && in_array('m:' . $mm[1], J(q("SELECT items FROM players WHERE id=?", [$uid])->fetchColumn(), []), true));
                if (!$okE || $ct <= (int)$mp['tick'] || $ct > $tick + 200 || $ct > Q4_TICKS + 200) continue; // 2v2 estendido: aceita até Q4_TICKS (tempo + prorrogação)
                if (strncmp($id, 'e:', 2) === 0 && $emN++ >= 1) continue; // anti-spam de emotes
                q("INSERT INTO moves(match_id,side,tick,card,x,y) SELECT CAST(? AS INTEGER),CAST(? AS INTEGER),CAST(? AS INTEGER),CAST(? AS TEXT),CAST(? AS DOUBLE PRECISION),CAST(? AS DOUBLE PRECISION) WHERE EXISTS (SELECT 1 FROM match_players WHERE match_id=? AND slot=? AND dropped=0)",
                  [$mid, $slot, $ct, $id, max(0, min(Q4_W, (float)($c['x'] ?? 0))), max(0, min(Q4_H, (float)($c['y'] ?? 0))), $mid, $slot]);
            }
            q("UPDATE match_players SET tick=?, seen=? WHERE match_id=? AND slot=? AND dropped=0", [$tick, $now, $mid, $slot]);
            $db->commit();
            q4_drop($mid, $now);
            $m = q("SELECT * FROM matches WHERE id=?", [$mid])->fetch(PDO::FETCH_ASSOC);
            $mp = q("SELECT * FROM match_players WHERE match_id=? AND slot=?", [$mid, $slot])->fetch(PDO::FETCH_ASSOC);
        }
        if (!empty($in['wait']) && $m['status'] === 'live' && !(int)$mp['dropped']) { // espera até o mais lento dos outros avançar (máx. 1,5 s)
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $c0 = (int)($in['ot'] ?? 0); $t0 = microtime(true);
            while (microtime(true) - $t0 < 1.5) {
                $o = q("SELECT (SELECT MIN(tick) FROM match_players WHERE match_id=? AND slot<>? AND dropped=0) AS mt, (SELECT COUNT(*) FROM moves WHERE match_id=? AND side>0 AND side<>? AND id>?) AS n, (SELECT status FROM matches WHERE id=?) AS st",
                       [$mid, $slot, $mid, $slot, $since, $mid])->fetch(PDO::FETCH_ASSOC);
                if ($o['mt'] === null || (int)$o['mt'] > $c0 || (int)$o['n'] > 0 || $o['st'] !== 'live') break;
                usleep(50000);
            }
        }
        $ts = q("SELECT slot,tick,dropped FROM match_players WHERE match_id=?", [$mid])->fetchAll(PDO::FETCH_ASSOC); // lê os ticks ANTES dos lances (consistência do lockstep)
        $mv = q("SELECT id,side AS slot,tick,card,x,y FROM moves WHERE match_id=? AND side>0 AND side<>? AND id>? ORDER BY id", [$mid, $slot, $since])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mv as &$mq) { $mq['x'] = (float)$mq['x']; $mq['y'] = (float)$mq['y']; } unset($mq);
        $st = q("SELECT status,result FROM matches WHERE id=?", [$mid])->fetch(PDO::FETCH_ASSOC);
        $ticks = []; $outs = [];
        foreach ($ts as $r) { $ticks[(int)$r['slot']] = (int)$r['tick']; if ((int)$r['dropped']) $outs[] = (int)$r['slot']; }
        out(['status' => $st['status'], 'result' => $st['result'], 'slot' => $slot, 'ticks' => (object)$ticks, 'outs' => $outs, 'moves' => $mv, 'players' => empty($in['tick']) ? q4_players($mid) : null, 'cfg' => empty($in['tick']) ? Q4_CFG : null, 'moon' => moon_code((int)$m['created'])]);

    case 'q4_end': // cada jogador relata o resultado (A, B, draw ou quit); o servidor fecha por consenso e paga a recompensa uma única vez
        $u = need(); $uid = (int)$u['id']; $now = time(); $mid = (int)($in['m'] ?? 0);
        $m = q("SELECT * FROM matches WHERE id=? AND mode='2v2'", [$mid])->fetch(PDO::FETCH_ASSOC);
        $mp = $m ? q("SELECT * FROM match_players WHERE match_id=? AND player_id=?", [$mid, $uid])->fetch(PDO::FETCH_ASSOC) : null;
        if (!$m || !$mp || !in_array($m['status'], ['live', 'done'], true)) fail('Partida não encontrada.');
        $r = (string)($in['r'] ?? ''); $slot = (int)$mp['slot'];
        if ($r === 'quit') { q("UPDATE match_players SET dropped=1, seen=? WHERE match_id=? AND slot=?", [$now, $mid, $slot]); q4_alive($mid); }
        elseif (in_array($r, ['A', 'B', 'draw'], true)) { q("UPDATE match_players SET rep=?, rep_t=?, seen=? WHERE match_id=? AND slot=? AND rep IS NULL", [$r, $now, $now, $mid, $slot]); q4_decide($mid, $now); }
        else fail('Resultado inválido.');
        $m = q("SELECT * FROM matches WHERE id=?", [$mid])->fetch(PDO::FETCH_ASSOC);
        $mp = q("SELECT * FROM match_players WHERE match_id=? AND slot=?", [$mid, $slot])->fetch(PDO::FETCH_ASSOC);
        $left = $r === 'quit' || (int)$mp['dropped']; // quem abandonou/caiu conta derrota (a menos que dê empate)
        if ($m['status'] !== 'done' && !$left) out(['pending' => 1]);
        $final = (string)$m['result']; $mine = q4_team($slot) === 1 ? 'A' : 'B';
        $res = $final === 'draw' ? 'draw' : ($left ? 'loss' : ($final === $mine ? 'win' : 'loss'));
        if (!pay_once($mid, $uid)) out(['user' => me(), 'res' => $res, 'delta' => 0, 'gold' => 0, 'gems' => 0, 'cw' => 0, 'cb' => 0, 'card' => null]);
        hook('match.end', ['m' => $mid, 'result' => $final, 'mode' => '2v2']);
        $dt = $res === 'win' ? 30 : ($res === 'loss' ? -12 : 0); $g = $res === 'win' ? 55 : 12; $gm = $res === 'win' ? 2 : 0;
        $c = max(0, min(count(Q4_TB) - 1, (int)($in['c'] ?? 0))); $cb = 0; // torres inimigas derrubadas (0-4: 2 Reis + 2 Princesas)
        if ($res === 'win') { $cb = (int)round($dt * Q4_TB[$c]) - $dt; $dt += $cb; }
        $card = null; if ($res === 'win' && mt_rand(1, 100) <= 30) $card = grantCard($u);
        $nt = max(0, $u['trophies'] + $dt);
        q("UPDATE players SET trophies=?, gold=gold+?, gems=gems+?, wins=wins+?, losses=losses+?, best=" . MX . "(COALESCE(best,0),?) WHERE id=?",
          [$nt, $g, $gm, $res === 'win' ? 1 : 0, $res === 'loss' ? 1 : 0, $nt, $uid]);
        tour_add($u, $res === 'win' ? 10 : ($res === 'draw' ? 4 : 2), $res === 'win'); // Torneio de Clãs
        $myc = array_values(array_filter(q("SELECT card FROM moves WHERE match_id=? AND side=?", [$mid, $slot])->fetchAll(PDO::FETCH_COLUMN), 'is_card_play'));
        $mev = [];
        if ($now - (int)$m['created'] >= 30 && count($myc) >= 2) {
            $mev = ['play' => 1, 'elem' => mis_elem_counts($myc), 'towers' => $c, 'elo' => mis_elo_count($mid, $slot)]; // Elo de Dupla conferido nas jogadas dos dois parceiros
            if ($res === 'win') { $mev['win'] = 1; $mev['win2v2'] = 1; }
        }
        $mdone = mission_event($uid, $mev); // Missões diárias
        out(['user' => me(), 'res' => $res, 'delta' => $nt - $u['trophies'], 'cw' => $c, 'cb' => $cb, 'gold' => $g, 'gems' => $gm, 'card' => $card, 'mdone' => $mdone]);

    // ---------- Oficina de Criaturas: criar carta personalizada (5.000 moedas) ----------
    case 'craft_card':
        $u = need(); $uid = (int)$u['id'];
        $base = (string)($in['base'] ?? '');
        $b = q("SELECT * FROM cards WHERE id=? AND kind='unit' AND active=1", [$base])->fetch(PDO::FETCH_ASSOC);
        if (!$b || !in_array($base, $u['owned'], true)) fail('Escolha uma criatura que você possui como base.');
        $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if (!preg_match('/^[\p{L}\p{N} \'\-]{3,16}$/u', $name)) fail('Nome: 3 a 16 letras, números ou espaços.');
        $emo = (string)($in['emoji'] ?? '');
        if (!in_array($emo, CRAFT_EMOJI, true)) fail('Ícone inválido.');
        $pc = function ($k) use ($in) { return max(-CRAFT_PCT, min(CRAFT_PCT, (int)($in[$k] ?? 0))); };
        $hp = max(10, (int)round($b['hp'] * (1 + $pc('hp') / 100))); $dm = max(1, (int)round($b['dmg'] * (1 + $pc('dmg') / 100))); $sp = max(30, min(100, (int)round($b['spd'] * (1 + $pc('spd') / 100))));
        $cls = $sp >= 76 ? 0 : ($sp >= 56 ? 1 : 2); $rate = (float)$b['rate']; $rng = (float)$b['rng']; $cost = (int)$b['cost'];
        // regra de equilíbrio: a criatura pode TROCAR atributos, nunca ficar mais forte que a base para o mesmo custo de mana
        if (craft_power($hp, $dm, $rate, $rng, $cost, $cls) > craft_power((int)$b['hp'], (int)$b['dmg'], $rate, $rng, $cost, (int)$b['spd_class']) + 0.01) fail('Essa combinação é forte demais para o custo de mana da criatura. Reduza algum atributo.');
        if ((int)q("SELECT COUNT(*) FROM custom_cards WHERE owner=?", [$uid])->fetchColumn() >= CRAFT_MAX) fail('Limite de ' . CRAFT_MAX . ' criaturas personalizadas.');
        if (q("SELECT 1 FROM custom_cards WHERE owner=? AND LOWER(name)=LOWER(?)", [$uid, $name])->fetchColumn()) fail('Você já tem uma criatura com esse nome.');
        $db->beginTransaction();
        try {
            // cobrança atômica: só debita se o saldo cobrir; qualquer falha depois disso desfaz o débito (rollback)
            if (!q("UPDATE players SET gold=gold-? WHERE id=? AND gold>=?", [CRAFT_COST, $uid, CRAFT_COST])->rowCount()) {
                $db->rollBack(); $g = (int)q("SELECT gold FROM players WHERE id=?", [$uid])->fetchColumn();
                fail('Moedas insuficientes: faltam ' . number_format(CRAFT_COST - $g, 0, ',', '.') . '.');
            }
            $n = (int)q("SELECT COALESCE(MAX(CAST(SUBSTR(id, LENGTH(?)+1) AS INTEGER)),0)+1 FROM custom_cards WHERE owner=?", ['x' . $uid . '_', $uid])->fetchColumn();
            $cid = 'x' . $uid . '_' . $n;
            q("INSERT INTO custom_cards(id,owner,name,emoji,base,cost,hp,dmg,spd,spd_class,rate,rng,ctype,rarity,created) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
              [$cid, $uid, $name, $emo, $base, $cost, $hp, $dm, $sp, $cls, $rate, $rng, (int)$b['ctype'], (int)$b['rarity'], time()]);
            $own = $u['owned']; $own[] = $cid; q("UPDATE players SET owned=? WHERE id=?", [json_encode($own), $uid]);
            $db->commit();
        } catch (Exception $e) { if ($db->inTransaction()) $db->rollBack(); fail('Não foi possível registrar agora. Nenhuma moeda foi cobrada.'); }
        hook('card.craft', ['player' => $uid, 'card' => $cid, 'name' => $name]);
        out(['user' => me(), 'card' => $name, 'id' => $cid, 'cost' => CRAFT_COST]);

    // ---------- Loja de visuais, troca de nome ----------
    case 'rename':
        $u = need(); $n = trim($in['name'] ?? '');
        if (empty($in['confirm'])) fail('Confirme a troca de nome.');
        if (!preg_match('/^[\p{L}\p{N}_ ]{3,16}$/u', $n)) fail('Nome: 3 a 16 letras ou números.');
        if ($n === $u['name']) fail('Esse já é o seu nome.');
        if ($u['gold'] < RENAME) fail('Trocar de nome custa ' . RENAME . ' moedas.');
        if (q("SELECT 1 FROM players WHERE lower(name)=lower(?) AND id<>?", [$n, $u['id']])->fetch()) fail('Esse nome já está em uso.');
        try { $st = q("UPDATE players SET name=?, gold=gold-? WHERE id=? AND gold>=?", [$n, RENAME, $u['id'], RENAME]); } catch (Exception $e) { fail('Esse nome já está em uso.'); }
        if (!$st->rowCount()) fail('Moedas insuficientes.');
        out(['user' => me()]);

    case 'item_buy':
        $u = need(); $id = $in['id'] ?? '';
        if (!isset(ITEMS[$id])) fail('Item inexistente.');
        if (in_array($id, $u['items'])) fail('Você já tem esse item.');
        [$cur, $p] = ITEMS[$id]; $f = $cur === 'g' ? 'gold' : 'gems';
        if ($u[$f] < $p) fail($cur === 'g' ? 'Moedas insuficientes.' : 'Diamantes insuficientes.');
        $st = q("UPDATE players SET $f=$f-? WHERE id=? AND $f>=?", [$p, $u['id'], $p]);
        if (!$st->rowCount()) fail('Saldo insuficiente.');
        $it = $u['items']; $it[] = $id; sv($u['id'], 'items', $it);
        out(['user' => me()]);

    case 'item_equip':
        $u = need(); $s = $in['slot'] ?? ''; $id = $in['id'] ?? '';
        if (!in_array($s, ['b', 't'])) fail('Espaço inválido.');
        if ($id !== '' && (!in_array($id, $u['items']) || $id[0] !== $s)) fail('Você não tem esse item.');
        $e = J(q("SELECT eqp FROM players WHERE id=?", [$u['id']])->fetchColumn(), []); $e[$s] = $id;
        q("UPDATE players SET eqp=? WHERE id=?", [json_encode($e), $u['id']]);
        out(['user' => me()]);

    // ---------- Amigos ----------
    case 'friend_add':
        $u = need(); $t = fid($in['name'] ?? '');
        if (!$t || $t == $u['id']) fail('Jogador não encontrado.');
        $ex = q("SELECT a,b,status FROM friends WHERE (a=? AND b=?) OR (a=? AND b=?)", [$u['id'], $t, $t, $u['id']])->fetch(PDO::FETCH_ASSOC);
        if ($ex && $ex['status'] === 'ok') fail('Vocês já são amigos.');
        if ($ex && $ex['a'] == $t) { q("UPDATE friends SET status='ok' WHERE a=? AND b=?", [$t, $u['id']]); out(['ok' => 1, 'msg' => 'Agora vocês são amigos! 🎉']); }
        if ($ex) fail('Pedido já enviado. Aguarde a resposta.');
        if (q("SELECT COUNT(*) FROM friends WHERE (a=? OR b=?) AND status='ok'", [$u['id'], $u['id']])->fetchColumn() >= 100) fail('Limite de 100 amigos.');
        q("INSERT INTO friends(a,b,status) VALUES(?,?,'pending')", [$u['id'], $t]);
        out(['ok' => 1, 'msg' => 'Pedido de amizade enviado!']);

    case 'friend_accept':
        $u = need(); $t = fid($in['name'] ?? '');
        q("UPDATE friends SET status='ok' WHERE a=? AND b=? AND status='pending'", [$t, $u['id']]);
        out(['ok' => 1]);

    case 'friend_remove':
        $u = need(); $t = fid($in['name'] ?? '');
        q("DELETE FROM friends WHERE (a=? AND b=?) OR (a=? AND b=?)", [$u['id'], $t, $t, $u['id']]);
        out(['ok' => 1]);

    case 'friend_list':
        $u = need(); $on = time() - ONLINE;
        $f = q("SELECT p.name,p.avatar,p.title,p.trophies,(p.seen>=?) AS online FROM friends f JOIN players p ON p.id=(CASE WHEN f.a=? THEN f.b ELSE f.a END)
                WHERE (f.a=? OR f.b=?) AND f.status='ok' ORDER BY online DESC, p.trophies DESC", [$on, $u['id'], $u['id'], $u['id']])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($f as &$x) $x['online'] = (bool)$x['online']; unset($x);
        $in_ = q("SELECT p.name,p.avatar,p.trophies FROM friends f JOIN players p ON p.id=f.a WHERE f.b=? AND f.status='pending'", [$u['id']])->fetchAll(PDO::FETCH_ASSOC);
        $out_ = q("SELECT p.name,p.avatar FROM friends f JOIN players p ON p.id=f.b WHERE f.a=? AND f.status='pending'", [$u['id']])->fetchAll(PDO::FETCH_ASSOC);
        out(['friends' => $f, 'incoming' => $in_, 'outgoing' => $out_]);

    case 'webhook': // entrada: sistemas externos (bots, Discord, etc.) enviam eventos assinados com X-Encantados-Signature
        $cf = __DIR__ . '/webhook.json'; $c = is_file($cf) ? json_decode(file_get_contents($cf), true) : [];
        $raw = file_get_contents('php://input'); $sig = $_SERVER['HTTP_X_ENCANTADOS_SIGNATURE'] ?? '';
        if (empty($c['secret']) || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $c['secret']), $sig)) { http_response_code(401); out(['error' => 'Assinatura inválida.']); }
        if (($in['event'] ?? '') === 'invite') { // {"event":"invite","data":{"from":"Ana","to":"Beto"}}
            $h = fid($in['data']['from'] ?? ''); $t = fid($in['data']['to'] ?? '');
            if (!$h || !$t || $h == $t) fail('Jogadores inválidos.');
            $wm = qid("INSERT INTO matches(host,status,created,hseen,invite,mode) VALUES(?,'wait',?,?,?,'pvp')", [$h, time(), time() + 600, $t]);
            out(['ok' => 1, 'm' => $wm]);
        }
        out(['ok' => 1]);

    // ---------- Insígnias ----------
    case 'ach':
        $u = need(); $a = ach_calc($u['id']);
        if ($a && $a['dirty']) q("UPDATE players SET ach=? WHERE id=?", [json_encode($a['st']), $u['id']]);
        out(ach_public($a));

    case 'ach_claim': // resgata os níveis liberados de uma insígnia (id) ou de todas (id vazio)
        $u = need(); $id = (string)($in['id'] ?? '');
        $gold = 0; $gems = 0; $n = 0;
        wtx_begin(); // trava de escrita: dois cliques/abas não resgatam duas vezes
        try {
            q("SELECT id FROM players WHERE id=?" . fu(), [$u['id']])->fetchColumn(); // PostgreSQL: trava a linha do jogador até o COMMIT
            $a = ach_calc($u['id']); $st = $a['st'];
            foreach ($a['list'] as $b) {
                if ($id !== '' && $id !== 'all' && $id !== $b['id']) continue;
                for ($i = $b['claimed']; $i < $b['tier']; $i++) { $gold += ACH_REW[$i][0]; $gems += ACH_REW[$i][1]; $n++; }
                if ($b['tier'] > $b['claimed']) $st['cl'][$b['id']] = $b['tier'];
            }
            if ($n) q("UPDATE players SET gold=gold+?, gems=gems+?, ach=? WHERE id=?", [$gold, $gems, json_encode($st), $u['id']]);
            wtx_commit();
        } catch (Exception $e) { wtx_rollback(); throw $e; }
        if (!$n) fail('Nenhuma insígnia para resgatar agora.');
        out(['user' => me(), 'gold' => $gold, 'gems' => $gems, 'n' => $n, 'ach' => ach_public(ach_calc($u['id']))]);

    // ---------- Torneio de Clãs ----------
    case 'tour':
        $u = me(); t_tick(); [$s, $d] = t_now();
        $my = null;
        if ($u && $u['clan_id']) {
            $cid = (int)$u['clan_id'];
            $reg = (bool)q("SELECT 1 FROM tour_clans WHERE season=? AND clan_id=?", [$s, $cid])->fetch();
            $my = ['id' => $cid, 'name' => $u['clan'], 'reg' => $reg,
              'pts' => (int)q("SELECT COALESCE(SUM(pts),0) FROM tour_pts WHERE season=? AND clan_id=?", [$s, $cid])->fetchColumn(),
              'today' => t_dpts($s, $cid, $d),
              'mvp' => q("SELECT p.name,p.avatar,m.pts FROM tour_mvp m JOIN players p ON p.id=m.player_id WHERE m.season=? AND m.clan_id=? ORDER BY m.pts DESC LIMIT 10", [$s, $cid])->fetchAll(PDO::FETCH_ASSOC),
              'mine' => (int)q("SELECT COALESCE(pts,0) FROM tour_mvp WHERE season=? AND player_id=?", [$s, $u['id']])->fetchColumn()];
        }
        $br = q("SELECT rnd,slot,a,b,an,bn,ap,bp,winner FROM tour_bracket WHERE season=? ORDER BY rnd,slot", [$s])->fetchAll(PDO::FETCH_ASSOC);
        if ($d >= T_QDAYS) foreach ($br as &$m) if ($m['winner'] === null && $m['rnd'] == $d - T_QDAYS) { $m['ap'] = $m['a'] ? t_dpts($s, $m['a'], $d) : 0; $m['bp'] = $m['b'] ? t_dpts($s, $m['b'], $d) : 0; $m['live'] = 1; } unset($m);
        $rw = $u ? q("SELECT id,season,place,clan,data FROM tour_rew WHERE player_id=? AND claimed=0 ORDER BY id", [$u['id']])->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rw as &$x) $x['data'] = J($x['data'], []); unset($x);
        $badges = $u ? J(q("SELECT badges FROM players WHERE id=?", [$u['id']])->fetchColumn(), []) : [];
        out(['season' => $s, 'day' => $d, 'now' => time(), 'start' => t_start($s), 'qdays' => T_QDAYS, 'len' => T_LEN, 'rounds' => T_ROUNDS,
             'rank' => t_qual($s, 50), 'bracket' => $br, 'my' => $my, 'rewards' => $rw, 'badges' => $badges, 'table' => T_REW,
             'gmvp' => q("SELECT p.name,p.avatar,m.pts,c.name AS clan FROM tour_mvp m JOIN players p ON p.id=m.player_id LEFT JOIN clans c ON c.id=m.clan_id WHERE m.season=? ORDER BY m.pts DESC LIMIT 10", [$s])->fetchAll(PDO::FETCH_ASSOC),
             'clans' => (int)q("SELECT COUNT(*) FROM tour_clans WHERE season=?", [$s])->fetchColumn(),
             'last' => q("SELECT season,name,runner,pts FROM tour_hall WHERE clan_id>0 ORDER BY season DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null]);

    case 'tour_join':
        $u = need(); t_tick(); [$s, $d] = t_now();
        if (!$u['clan_id']) fail('Entre em um clã para disputar o torneio.');
        if ($d >= T_QDAYS) fail('As inscrições desta temporada já fecharam. Volte na próxima!');
        q("INSERT INTO tour_clans(season,clan_id,name,t) VALUES(?,?,?,?) ON CONFLICT DO NOTHING", [$s, $u['clan_id'], $u['clan'], time()]);
        hook('tour.join', ['season' => $s, 'clan' => $u['clan']]);
        out(['ok' => 1]);

    case 'tour_hall':
        $l = q("SELECT season,name,descr,leader,members,runner,pts,t FROM tour_hall WHERE clan_id>0 ORDER BY season DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($l as &$x) $x['members'] = J($x['members'], []); unset($x);
        $tt = q("SELECT (SELECT h2.name FROM tour_hall h2 WHERE h2.clan_id=h.clan_id ORDER BY h2.season DESC LIMIT 1) AS name, COUNT(*) AS n FROM tour_hall h WHERE h.clan_id>0 GROUP BY h.clan_id ORDER BY n DESC, MAX(h.season) DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        out(['list' => $l, 'titles' => $tt]);

    case 'tour_claim':
        $u = need();
        $r = q("SELECT * FROM tour_rew WHERE id=? AND player_id=? AND claimed=0", [(int)($in['id'] ?? 0), $u['id']])->fetch(PDO::FETCH_ASSOC);
        if (!$r) fail('Recompensa não encontrada ou já resgatada.');
        if (!q("UPDATE tour_rew SET claimed=1 WHERE id=? AND claimed=0", [$r['id']])->rowCount()) fail('Recompensa já resgatada.');
        $x = J($r['data'], []); $gold = (int)($x['gold'] ?? 0); $card = null;
        if (!empty($x['myth'])) {
            $pool = array_values(array_filter(array_keys(RARITY), function ($k) use ($u) { return RARITY[$k] == 4 && !in_array($k, $u['owned'], true); }));
            if ($pool) { $card = $pool[mt_rand(0, count($pool) - 1)]; $own = $u['owned']; $own[] = $card; q("UPDATE players SET owned=? WHERE id=?", [json_encode($own), $u['id']]); }
            else $gold += 3000; // já tem todas as míticas
        }
        q("UPDATE players SET gold=gold+?, gems=gems+? WHERE id=?", [$gold, (int)($x['gems'] ?? 0), $u['id']]);
        $bn = $x['banner'] ?? '';
        if ($bn && !in_array($bn, $u['items'])) { $it = $u['items']; $it[] = $bn; sv($u['id'], 'items', $it); }
        if (!empty($x['ins'])) { $b = J(q("SELECT badges FROM players WHERE id=?", [$u['id']])->fetchColumn(), []); $b[] = ['i' => $x['ins'], 's' => (int)$r['season'], 'c' => $r['clan']]; q("UPDATE players SET badges=? WHERE id=?", [json_encode($b, JSON_UNESCAPED_UNICODE), $u['id']]); }
        out(['user' => me(), 'gold' => $gold, 'gems' => (int)($x['gems'] ?? 0), 'card' => $card, 'banner' => $bn, 'ins' => $x['ins'] ?? '']);

    default: fail('Ação desconhecida.');
}
