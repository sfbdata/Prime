# Contribua: modo campanha "BlueJus Black" (Desafio de R$ 1,00)

Pedido do Farlei de 19/11/2026 (item C1 de `bluejus-coordenacao/pedidos-farlei-1911.md`), pelo Coordenador.
Desenho: `docs/design/contribua-19-11-26/` (cópia do projeto "Redesign de pasta jurídica" no Claude Design):
`08 - Contribua -  VS 03.11.26.dc.html` (seções `#black` e `#campanha`, FAQ, estado `cpVals` no JS),
`bj-campanha.js` (aba do topo, janela da campanha, regras geradas) e `ESPEC_CAMPANHA.md` (dados e regras de negócio).
Branch `contribua-campanha`, um PR. Migration `Version20261119120000` (número dado pelo Coordenador).
Risco BAIXO (Contribua), mas mexe com dinheiro: uma revisão no fim.

## Decisões (Coordenador aprovou o plano em 19/11)

- D1. Pix do Desafio pelo `CodigoPixDaConta` que já existe (estático, chave CNPJ). O txid é da inscrição:
  `BJB<id da participação>` (até 25 caracteres, só letras e números). A Cora foi cancelada; o gateway é só o Asaas
  e o Asaas não entra aqui.
- D2. A inscrição nasce "aguardando pagamento". O Master confere o extrato e clica "Confirmar pagamento" no painel
  da campanha (extra necessário: o desenho simula o pagamento; a ESPEC exige confirmação antes de valer).
- D3. Chave Pix da devolução guardada cifrada (sodium secretbox) com chave derivada do `kernel.secret`
  (`sodium_crypto_generichash('contribua-campanha-pix' . secret, '', 32)`), para não depender da
  `CONTRIBUA_CRYPTO_KEY`, que não está configurada em todo ambiente. Só o Master vê a chave decifrada, no painel.
- D4. CPF guardado só como hash (`hash_hmac('sha256', digitos, kernel.secret)`) e os 2 últimos dígitos. CPF
  válido = 11 dígitos com dígitos verificadores corretos e não todos iguais.
- D5. A migration cria a campanha padrão do desenho (`PADRAO` do `bj-campanha.js`), **desligada**
  (decisão do Samuel, 19/11), de 19/11/2026 a 30/11/2026. O Farlei liga no painel do Master; até lá não há aba, faixa
  nem item do FAQ para os usuários (o Master vê a prévia da aba). A validação jurídica da ESPEC fica anotada em
  `bluejus-coordenacao/pacote-juridico.md` (jurídico não trava, regra de 04/11).
- D6. A aba do topo entra pelo `templates/layout/_globais.html.twig` (parcial `layout/_globais/campanha.html.twig`),
  sem tocar no `base.html.twig`.
- D6b. **Só para escritório novo** (pedido do Farlei, 19/11): aba, faixa, item do FAQ, janela e inscrição valem só
  para o escritório criado há até 5 dias (`CampanhaVigente::DIAS_DA_CONTA_NOVA`, contados em horas a partir de
  `tenant.created_at`; sem data, não vale). Escritório mais antigo recebe `campanha()` null, `/janela` 404 e a
  inscrição é recusada ("O Desafio é só para escritórios criados há até 5 dias."). Exceções: o Master continua vendo
  a prévia e a janela (mas, em escritório antigo, também não se inscreve); quem já se inscreveu continua vendo a aba e a própria participação (pagar o Pix, pedir a
  devolução) mesmo depois que o escritório passa dos 5 dias. Sem migration.
- D7. Fora do sistema real (só do protótipo): "Zerar participação de teste" e "Simular pagamento confirmado".
  O estado "arrependimento" da ESPEC não ganha fluxo próprio: o prazo do Desafio (mínimo 7 dias) cobre o art. 49 e
  devolve mais; a regra 5 continua publicada.
- D8. Vagas: contam todas as inscrições da campanha (inclusive aguardando pagamento), para não vender além do
  limite. O contador público ("N inscrito(s)") conta só as pagas.
- D9. Datas no fuso `America/Sao_Paulo`. Prazo da devolução conta da confirmação do pagamento (`pago_em`).

## Dados (migration Version20261119120000)

Tabelas globais (sem `tenant_id` de filtro: a campanha é do BlueJus, a inscrição é da pessoa). Nenhuma entidade
implementa `TenantAware`.

`contribua_campanha`: id, nome (varchar 60), chamada (varchar 60), preco_centavos (int, >= 100), multiplicador
(smallint, 2 ou 3), prazo_pedido_dias (smallint, >= 7), prazo_pagamento_dias_uteis (smallint, >= 1), inicio (date),
fim (date), vagas (int, 0 = sem limite), mostrar_contador (bool), aba_no_topo (bool), beneficios (json, lista de
textos), ligada (bool), versao_regras (int, começa em 1 e sobe a cada alteração salva), atualizada_em
(datetime_immutable), atualizada_por_id (FK user, null, ON DELETE SET NULL).

`contribua_campanha_alteracao` (trilha, regra 8): id, campanha_id (FK CASCADE), usuario_id (FK user SET NULL),
em, antes (json), depois (json).

`contribua_campanha_participacao`: id, campanha_id (FK), usuario_id (FK user, CASCADE), tenant_id (FK tenant, SET
NULL, só referência de onde se inscreveu), nome (varchar 120), cpf_hash (char 64), cpf_final (char 2), oab
(varchar 20, maiúsculas sem espaços), valor_centavos, multiplicador, prazo_pedido_dias,
prazo_pagamento_dias_uteis, versao_regras (congelados da campanha na inscrição, regra 3), situacao (varchar 30:
`aguardando_pagamento`, `ativa`, `devolucao_pedida`, `devolvida`), txid (varchar 25), aceite_regras_em, ip
(varchar 45), criada_em, pago_em (null), pagamento_confirmado_por_id (FK user SET NULL).
Únicos: (campanha_id, usuario_id), (campanha_id, cpf_hash), (campanha_id, oab).

`contribua_campanha_devolucao`: id, participacao_id (FK CASCADE, único), valor_centavos, chave_pix_cifrada (text),
motivo (varchar 400, null), pedida_em, devolvida_em (null), devolvida_por_id (FK user SET NULL).

Insert da campanha padrão: nome "BlueJus Black", chamada "Black Friday BlueJus", 100 centavos, multiplicador 2,
prazo 30, pagamento 7, 2026-11-19 a 2026-11-30, vagas 0, contador false, aba true, ligada false, versão 1, benefícios:
"Implantação assistida: importamos suas pastas, clientes e prazos"; "Suporte prioritário pelo WhatsApp durante a
campanha"; "Treinamento ao vivo para a sua equipe"; "Selo \"Fundador BlueJus Black\" no seu perfil".

## Estado da campanha (`EstadoDaCampanha`, enum string)

`desligada` (não ligada) · `agendada` (hoje < início) · `encerrada` (hoje > fim) · `esgotada` (vagas > 0 e
inscrições >= vagas) · `ativa`. A campanha vigente é a de maior id (só existe uma por enquanto).

## Contratos do servidor (implementador A)

Domínio `app/src/Contribua/`. Arquivos novos com prefixo `Campanha` onde couber.

Serviço `RegrasDaCampanha::gerar(Campanha): list<string>`: as 10 frases do `regras()` do `bj-campanha.js`,
literais, com os valores da configuração (preço e devolução em "R$ 1,00", datas dd/mm/aaaa, "o dobro"/"o triplo").

Twig function global `campanha()` (extensão em `app/src/Contribua/Twig/`), memorizada por requisição; devolve
`?CampanhaView` para o usuário logado com escritório (null sem campanha, sem usuário, sem escritório ou, ativa ou
esgotada, para escritório com mais de 5 dias sem inscrição da pessoa: D6b):

```
CampanhaView (readonly):
  nome, chamada, chamadaSelo (chamada em maiúsculas), chamadaAba (2 primeiras palavras em maiúsculas)
  preco ("R$ 1,00"), devolve ("R$ 2,00"), multTx ("o dobro" | "o triplo"), prazoPedido (int), prazoPagto (int)
  inicio, fim ("dd/mm/aaaa"), estado (string do enum), ehMaster (bool)
  statusTexto: ativa "Inscrições abertas até <fim>" · esgotada "Vagas esgotadas" ·
               agendada "Prévia do Master · começa em <inicio>" · encerrada "Prévia do Master · encerrada em <fim>" · desligada ""
  statusJanela (o `stTx` do `abrir()`, na janela): ativa e esgotada como acima · agendada "Começa em <inicio>" ·
               encerrada "Campanha encerrada em <fim>" · desligada "Campanha desligada"
  mostrarAba: abaNoTopo && (ativa || esgotada || (ehMaster && estado != ativa))
  abaPrevia: estado não é ativa nem esgotada (só o Master chega a ver)
  abaTitulo: prévia "Prévia do Master: campanha <estado> (só você vê)" senão "<chamada>: Desafio <preco>"
  mostrarFaixa: ativa || esgotada || (ehMaster && estado != desligada)
  mostrarFaq: ativa || esgotada
  faqPago: mostrarFaq ? " Durante a campanha <nome>, existe também a inscrição opcional no Desafio, de <preco>, com devolução de <devolve> se você não gostar." : ""
  botao: "Minha participação" se o usuário tem inscrição, senão "Conhecer o Desafio"
```

Rotas (controller novo `CampanhaController`, prefixo `/contribua/campanha`, `ROLE_USER`; ações POST em JSON
`{ok, mensagem}` ou `{ok: false, erro}` com 403 para CSRF inválido e 422 para `\DomainException`; CSRF `contribua`
no campo `_token`, igual ao `ContribuaController`):

| Nome | Método e caminho | Quem | Entrada | Resultado |
|---|---|---|---|---|
| `contribua_campanha_janela` | GET `/janela` | logado com escritório | | HTML da janela (`contribua/campanha/_janela.html.twig` com `JanelaDaCampanhaView`) |
| `contribua_campanha_inscrever` | POST `/inscrever` | logado | nome, cpf, oab, aceite=1 | cria aguardando; mensagem "Inscrição registrada. Pague <preco> com o Pix para entrar no Desafio." |
| `contribua_campanha_devolucao` | POST `/devolucao` | dono da inscrição ativa no prazo | pix, motivo | "Pedido de devolução registrado. Você recebe <valor> por Pix em até <n> dias úteis." |
| `contribua_campanha_configurar` | POST `/configurar` | `ROLE_SUPER_ADMIN` | campos da tabela (abaixo) | salva, versão +1, trilha; "Campanha atualizada." |
| `contribua_campanha_padrao` | POST `/padrao` | Master | | volta aos valores padrão (mantém `ligada`), trilha |
| `contribua_campanha_confirmar` | POST `/participacoes/{id}/confirmar` | Master | | aguardando -> ativa, pago_em agora; "Pagamento confirmado." |
| `contribua_campanha_devolvida` | POST `/devolucoes/{id}/devolvida` | Master | | devolucao_pedida -> devolvida; "Devolução marcada como paga." |

Campos de `/configurar` (todos opcionais; só os enviados mudam): `ligada` (0/1), `nome`, `chamada` (vazio volta
ao padrão), `preco` (reais, mínimo 1), `multiplicador` (2|3), `inicio`, `fim` (Y-m-d; fim >= início),
`prazoPedido` (mínimo 7), `prazoPagto` (mínimo 1), `vagas` (mínimo 0), `abaNoTopo` (0/1), `mostrarContador` (0/1),
`beneficios` (texto, um por linha, vazios fora). Regra 3: com a campanha `ativa`, recusar ("Com a campanha no ar,
não dá para reduzir os benefícios. Mudanças valem só para novas inscrições.") diminuir o multiplicador, subir o
preço, reduzir prazos ou tirar benefício da lista.

Recusas da inscrição (DomainException, textos): campanha não está `ativa` "A campanha não está aberta para
inscrições."; já inscrito "Você já está no Desafio."; sem aceite "Para entrar, marque que leu e aceita as regras.";
CPF inválido "Informe um CPF válido."; OAB vazia "Informe sua inscrição na OAB."; CPF ou OAB já usados "Este CPF ou
esta inscrição na OAB já está no Desafio."; esgotada "Vagas esgotadas.". Concorrência: `pg_advisory_xact_lock(4304,
campanha_id)` na inscrição.

Devolução: só situação `ativa` e `hoje <= pago_em + prazo_pedido_dias` ("Fora do prazo ou sem inscrição ativa.");
chave Pix obrigatória ("Informe a chave Pix para receber."), até 140 caracteres; valor = valor × multiplicador
congelados.

```
JanelaDaCampanhaView: campanha (CampanhaView), regras (list<string>), beneficios (list<string>),
  inscritos (?int, só com mostrarContador e estado != desligada), vagas (int), nomeSugerido (nome do usuário),
  csrf (token 'contribua'), urls (inscrever, devolucao), urlConfigurar (só Master: path('contribua_index') ~ '#campanha'),
  participacao: ?{ situacao, desde ("dd/mm/aaaa" do pago_em ou criada_em), diasRestantes (int, >= 0),
                   podeDevolver (bool), valorDevolucao ("R$ 2,00"), pedidaEm (?"dd/mm/aaaa"),
                   pixCodigo (?string, só aguardando_pagamento), preco }

PainelDaCampanhaView (só Master, montado no ContribuaController::index como 'painelDaCampanha'):
  valores crus para os campos: nome, chamada, preco ("1.00"), multiplicador, inicio, fim (Y-m-d), prazoPedido,
  prazoPagto, vagas, abaNoTopo, mostrarContador, ligada, beneficiosTexto (um por linha)
  estadoRotulo (No ar | Esgotada | Agendada | Encerrada | Desligada), estadoCor (#1e7b45 | #9a5b00 | #0a6fa0 | #5f7684 | #5f7684)
  custo: vagas ? "<total> (<vagas> vagas × <devolve>), se todos pedirem" : "<devolve> por participante que pedir (sem limite de vagas definido)"
  pedidosResumo: sem devoluções "nenhum" senão "<n> pendente(s), <m> devolvido(s)"
  regras (list<string>), devolucoes: list<{id, nome, oab, valor, pix, em, motivo, pendente}>,
  aguardando: list<{id, nome, oab, cpfFinal, valor, txid, em}>, urls (configurar, padrao, confirmar e devolvida com id 0)
```

## Contratos da tela (implementador B)

Valores, cores e textos do CSS e do JS do desenho; componentes do desenho, não do Bootstrap/AdminLTE.

1. **Contribua, faixa `#black`** (entre a Apresentação e o Convênio), `{% if campanha() and campanha().mostrarFaixa %}`;
   botão abre a janela.
2. **FAQ**: "Existe alguma coisa paga?" ganha `faqPago`; novo item depois dele com `mostrarFaq`: "Se o BlueJus é
   gratuito, por que o Desafio de <preco>?" com o texto do desenho e o link "Ver as regras completas" (abre a janela).
3. **Painel do Master `#campanha`** (depois do Suporte, antes da Integração bancária), só `painelDaCampanha`:
   cabeçalho preto, selo MASTER, estado, interruptor; campos; "Ver página da campanha"; "Restaurar padrão"; resumo de
   custo e pedidos; regras em `<details>`; "Pedidos de devolução" com "Marcar como devolvido"; extra D2: lista
   "Aguardando Pix" (mesmo componente de linha dos pedidos, selo "Aguardando Pix" #9a5b00) com "Confirmar pagamento".
   Cada campo grava ao mudar (POST `/configurar` só com o campo).
4. **Aba do topo** (todas as telas logadas): parcial `layout/_globais/campanha.html.twig`, botão fixo do desenho
   (`aba()` do `bj-campanha.js`: preto, abaixo do cabeçalho, selo azul `#3aa0d8`, preço `#7fd0ff`,
   prévia com borda tracejada `#f0b429` e "PRÉVIA"). Posição do desenho: **pendurada no canto direito**
   (`right:24px`), com o topo na base da barra azul (`#mainHeader`, o `nav.app-header` do sistema; o `cabecalho()` do
   desenho só procura `header`/`[role=banner]` e, sem achar, punha a aba em `top:0`, por cima do nome e do avatar: o
   print do Farlei). O centro do topo (#851) foi desfeito a pedido do Samuel (19/11). Pedido do Farlei mantido: **some
   sozinha 5 s** depois de a tela abrir (some com transição de opacidade e é removida; a faixa, o FAQ e `#black` continuam abrindo
   a janela). Some com a janela de manutenção aberta não se aplica aqui.
5. **Janela da campanha**: aberta pela aba, pela faixa, pelo FAQ e por `#black` no endereço (em qualquer tela);
   carrega o HTML de `/contribua/campanha/janela` e o põe num overlay do desenho (`abrir()` do `bj-campanha.js`):
   cabeçalho com gradiente, 3 passos, caixa da ação conforme a situação, "O QUE O DESAFIO INCLUI" (+ "Todo o sistema,
   que já é gratuito, continua liberado"), regras em `<details open>`, rodapé da empresa, link "Configurar campanha
   (Master)". Esc e clique fora fecham. Caixa da ação: sem inscrição e `ativa` = formulário (Nome, CPF *, OAB *,
   aceite, "Pagar <preco> com Pix e entrar"); `aguardando_pagamento` = Pix copia e cola + QR (mesma lib do
   Contribua), "Copiar código" e texto "Sua inscrição vale quando o pagamento for confirmado. Valor: <preco>.";
   `ativa` = "Você está no Desafio desde..." com o formulário de devolução; `devolucao_pedida` = caixa âmbar;
   `devolvida` = caixa verde; senão a caixa cinza com o `statusJanela` (conferência de 19/11). Erro em toast
   vermelho do desenho (`#a3232b`, embaixo, 3,5 s). Depois de cada ação, recarrega a janela.
6. **Raio dos selos do desenho novo**: selo GRATUITO 4px; botões de contato do Suporte 4px; selo de estado da
   Integração bancária 4px.
