# Voto: assembleia de condomínio (mínimo para 10/11/2026)

Pedido do Samuel em 09/11/2026: votação de assembleia de condomínio pronta hoje, sem revisão de segurança externa e
sem criptografia. Não é eleição oficial. Risco: MÉDIO (dado de cliente, página pública), sem spec de protocolo.
Base: `docs/specs/bluejus-voto.md` (desenho 18) e o inventário `docs/specs/bluejus-voto-anexo-telas.md`.

## 1. O que entra (modelo do desenho: assembleia = evento, cada pauta = uma votação)

Insumo do desenho para o fluxo do condomínio, com textos exatos, CSS e regra de resultado:
`docs/specs/voto-assembleia-desenho.md` (§2 criar, §3 publicar, §4 eleitor, §5 mesa, resultado e ata, §7 CSS).

**Gestor** (equipe logada, `/voto`, aba Gestão eleitoral):
1. Painel: lista de assembleias e, em cada uma, uma `.vLin` por pauta com chip de status (Rascunho `#5b7383`,
   Publicada `#0a6fa0`, Votação aberta `#1e7b45`, Encerrada `#8a6200`), barra e "Participação {n} de {aptos} aptos ({pct}%).".
   Botão "Nova assembleia".
2. Criar: título (nome do evento), peso do voto ("Fração ideal" ou "Uma unidade, um voto"). Sem data (o desenho, no
   modo mesa, não mostra Início/Encerramento).
3. Pautas (só em rascunho): título, descrição, opções numeradas (padrão do cenário condomínio: 1 Sim, 2 Não). A
   abstenção é automática (o BRANCO do desenho vira "ABSTENÇÃO") e não conta como voto válido.
4. Unidades (enquanto nenhuma pauta foi aberta: "a lista bloqueia na abertura"): colar a lista, uma por linha,
   `unidade; bloco; situação; fração; responsável; e-mail` (colunas do desenho + responsável e e-mail para o link).
   Apta se a situação for vazia ou apto, adimplente, regular, quite (sem acento e sem caixa). Inapta aparece na lista e
   na ata ("Inaptos na lista") e não vota.
5. Publicar: exige 1 pauta com 2 opções e 1 unidade apta. Trava as pautas.
6. Links: "Copiar link" e "Copiar mensagem" (WhatsApp) por unidade; "Enviar links por e-mail" (lotes de 25, só quem tem
   e-mail e ainda não recebeu, "faltam N"); "Reenviar"; "Gerar novo link" (o anterior deixa de valer).
7. Mesa, por pauta (§5 do insumo): publicada: select "1ª convocação"/"2ª convocação", minutos (padrão 5),
   "Abrir por minutos", "Abrir sem prazo". Aberta: "Encerra em mm:ss" ou "Aberta, sem prazo definido", "Definir prazo",
   "Sem prazo", "Motivo do encerramento antecipado" + "Encerrar" (vazio vira "Determinação da mesa"). Prazo vencido
   encerra sozinha com motivo "Prazo definido pela mesa" (o servidor recusa voto depois do prazo e grava o encerramento
   na primeira leitura depois dele). Quórum exigido: 50% na 1ª convocação, 0% na 2ª.
8. Acompanhar: por pauta, "{n} de {aptos} votaram", "{pc}% do peso apto", barra com marca do quórum, "Quórum atingido"
   ou "Quórum exigido"; "Assistir" abre página própria para projetar (atualiza sozinha). Nunca parcial de votos.
9. Resultado (pauta encerrada; sem guardiões, o resultado sai no encerramento): regra do insumo §5 com critério "Mais
   votos" sobre votos válidos; situações literais "Decidido", "Quórum não atingido", "Empate", "Critério não atingido".
10. Ata imprimível (HTML com botão imprimir, no lugar do PDF em canvas): seções do insumo §5 que têm dado neste mínimo
    (resumo, identificação, por pauta abertura, eleitorado e participação por bloco, encerramento, apuração, relação de
    votantes "Não indica em quem cada pessoa votou.", termo de encerramento, assinaturas Presidência e Secretaria).

**Morador** (sem conta, link pessoal da unidade `/votar/{token}`; desvio do desenho: no lugar de "Localizar seu nome"
+ código + fotos, o link pessoal; decisão do mínimo):
1. Cabeçalho do portal do eleitor, assembleia, unidade e as pautas.
2. Pauta aguardando: "A votação desta pauta ainda não foi aberta." Aberta: escolhe uma opção ou ABSTENÇÃO e confirma
   no modal do desenho; depois "Você já votou nesta pauta." com a hora, sem mostrar em quê. Encerrada: o resultado.
3. Unidade inapta: vê as pautas e "Sua unidade não está apta a votar nesta assembleia."
4. Nada de "cifrado", "Protegendo seu voto", código BJV nem "Ambiente demonstrativo" (não há criptografia nem é
   demonstração). Aviso `.vAviso`: "Seu voto é secreto: o sistema registra que a unidade votou, mas não em quê."

## 2. Sigilo prático

A participação (unidade, pauta, hora) e o voto ficam separados. O voto soma no contador da opção
(`UPDATE ... SET total_unidades = total_unidades + 1, total_peso = total_peso + :peso`), na mesma transação do
INSERT da participação. Nenhuma linha liga unidade e opção. Opção e participação **não** são `Auditavel` e o contador
só muda por SQL direto (o log de auditoria guardaria a hora da mudança e ligaria as duas). Abstenção é uma opção com
`abstencao = true`.

## 3. Dados (migration `Version20261109170000`, schema `voto`)

Todas `TenantAware`, tenant ManyToOne `nullable:false`, FK `ON DELETE CASCADE` (mesma exceção da `voto.liberacao`;
este mínimo roda dentro do BlueJus; quando o Voto for para o container próprio, a M3 refaz sem FK). Todas entram em
`PurgarEscritorioUseCase::ORDEM_DELECAO` (filhas antes das mães) com `tenant_id = :tenant`.

| Tabela | Entidade | Colunas |
|---|---|---|
| `voto.assembleia` | `Assembleia` (Auditavel) | id, tenant_id, titulo varchar(160), peso varchar(10) (`fracao`/`unidade`, enum `PesoDoVoto`), estado varchar(12) (`rascunho`/`publicada`, enum `EstadoDaAssembleia`), criada_por_nome varchar(180), criada_em, publicada_em null |
| `voto.assembleia_pauta` | `PautaDaAssembleia` (Auditavel) | id, tenant_id, assembleia_id FK CASCADE, ordem smallint, titulo varchar(200), descricao text null, estado varchar(12) (`aguardando`/`aberta`/`encerrada`, enum `EstadoDaPauta`), convocacao smallint null (1/2), quorum_exigido numeric(5,2) null, aberta_em null, aberta_por_nome null, prazo_ate null, encerrada_em null, encerrada_por_nome null, motivo_encerramento varchar(200) null |
| `voto.assembleia_opcao` | `OpcaoDaPauta` | id, tenant_id, pauta_id FK CASCADE, numero smallint, rotulo varchar(120), abstencao bool default false, total_unidades int default 0, total_peso numeric(14,6) default 0 |
| `voto.assembleia_unidade` | `UnidadeDaAssembleia` | id, tenant_id, assembleia_id FK CASCADE, identificacao varchar(80), bloco varchar(40) not null default '', situacao varchar(40) null, apta bool, fracao numeric(12,6) null, responsavel varchar(160) null, email varchar(180) null, semente char(32), token_hash char(64) UNIQUE, link_enviado_em null; UNIQUE(assembleia_id, bloco, identificacao) |
| `voto.assembleia_participacao` | `ParticipacaoNaPauta` | id, tenant_id, unidade_id FK CASCADE, pauta_id FK CASCADE, registrada_em; UNIQUE(unidade_id, pauta_id) |

Transições: `Assembleia::publicar()` (de rascunho). `PautaDaAssembleia::abrir(string $nome, int $convocacao,
?DateTimeImmutable $prazoAte)` (assembleia publicada, pauta aguardando; grava quórum 50 ou 0),
`definirPrazo(?DateTimeImmutable)` (aberta), `encerrar(string $nome, string $motivo)` (aberta),
`aceitaVotoEm(DateTimeImmutable $agora)` (aberta e antes do prazo), `prazoVencidoEm($agora)`. Outra origem lança
exceção de domínio. Pautas mudam só em rascunho; unidades só enquanto nenhuma pauta saiu de aguardando.

## 4. Link da unidade

`App\Voto\Service\TokenDaUnidade`: `novaSemente()` = `bin2hex(random_bytes(16))`;
`token(semente)` = `substr(hash_hmac('sha256', 'voto-assembleia:'.$semente, %kernel.secret%), 0, 40)`;
`hash(token)` = `hash('sha256', $token)`. O banco guarda a semente e o hash, nunca o token; o link pode ser
mostrado de novo ao gestor (recalculado). "Gerar novo link" troca a semente.

## 5. Rotas

| Nome | Caminho | Quem |
|---|---|---|
| `voto_assembleia_*` (gestor) | `/voto/assembleias...` | equipe: User + tenant + `canAccessModule('voto')` + escritório liberado (igual a `VotoController::assertAcesso`) |
| `voto_assembleia_cedula` | GET `/votar/{token}` (`[a f0-9]{40}`) | público |
| `voto_assembleia_cedula_votar` | POST `/votar/{token}/pauta/{pauta}` | público, CSRF `voto_cedula_{token}` |

Nunca `voto_publico_*` (o `PapelDoContainer` dá 404 no BlueJus). O prefixo `voto_` deixa tudo sob a chave `voto`.
Página pública: `security.yaml` `^/votar/` PUBLIC_ACCESS; as duas rotas nas `ROTAS_IGNORADAS` do
`TenantContextValidatorListener` e do `TermoAceiteListener`; filtro de tenant desligado no prefixo
`voto_assembleia_cedula` (precedente `PushCompartilhadoSemTenantFilterListener`); escritório da assembleia sem
liberação do Voto dá 404; token inexistente dá 404 e consome o limitador `voto_cedula_invalido` (20/min por IP;
token válido não é limitado por IP: na assembleia presencial muitos moradores usam o mesmo wi-fi); cabeçalhos
noindex, no-store, no-referrer.

## 6. Visual

Componentes e valores do desenho 18 (`docs/design/redesign-08-11-26/18 - Voto - VS 08.11.26.dc.html`, inventário
em `docs/specs/bluejus-voto-anexo-telas.md`): `.vC`, `.vH`, `.vB`/`.vP`/`.vD`, `.vF`, `.vL`, `.vKpi`, `.vChip`,
`.vLin`, `.vMod`, toast, `.vAviso`, pílulas `.vPas`. Equipe: moldura do sistema + `css/voto.css`. Morador: página
própria sem moldura, cabeçalho branco do portal do eleitor, `css/voto-cedula.css`. Nada de modal Bootstrap, card ou
botão do AdminLTE. O resto do desenho 18 (cabine com urna, teclado, sons, PIN, cadastro com foto, guardiões,
boletins, QR Code, perfis, lista de aptos por planilha, sessão permanente) fica para o Voto oficial.

## 7. Frentes

- **F1 base:** migration, entidades, enums, repositórios, `TokenDaUnidade`, purga, testes unitários das transições.
  Cria o `CedulaController` com a rota GET só como esqueleto (a F3 preenche).
- **F2 gestor:** UseCases (criar, pautas, importar unidades, publicar, abrir, encerrar, links, e-mail, resultado),
  controller, telas, ata, e-mail `email/voto_assembleia_link.html.twig` (envio síncrono, `IdentidadeDeEmail`).
- **F3 morador:** `RegistrarVotoUseCase`, `CedulaController`, tela pública, `security.yaml`, limitador, listeners,
  filtro de tenant.
