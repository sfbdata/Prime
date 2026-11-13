# Voto: fluxo mínimo de ASSEMBLEIA DE CONDOMÍNIO, lido do desenho

Fonte: `bluejus-coordenacao/copia-desenhos-08-11-26/18 - Voto - VS 08.11.26.dc.html` (444 linhas, inteiro, termina em `</html>`),
`bj-voto-cenarios.js`, `bj-voto-nucleo.js`, `bj-voto-entrada.js`, `bj-voto-boletim.js`, `ESPEC_VOTO.md`.
Inventário bruto do script: `voto-inventario-bruto.md` (mesma pasta deste arquivo).
Números `L123` = linha do `.dc.html`; `N123` = linha do `bj-voto-nucleo.js`.

## Atualização de 13/11/2026: PR 5 das Pautas

Os controles da etapa 4 estão implementados nesta entrega: regra pronta, vagas, dígitos, critério, base,
dois quóruns e Numerar. Inventário, contrato e validação em [voto-pautas-controles-1311.md](voto-pautas-controles-1311.md).
A apuração por regra e várias vagas já foi publicada na v2.49.0; esta entrega libera sua configuração pela tela.
As demais tabelas abaixo conservam o inventário histórico de 10/11 e não representam o estado completo atual.

## Estado em 10/11/2026 e etapas até o desenho completo

O desenho novo do Voto (`docs/design/redesign-08-11-26/18 - Voto - VS 08.11.26.dc.html`, com `ESPEC_VOTO.md` e `bj-voto-*.js`) é mais novo que o lido nesta spec e traz a mais:
ata em Word (.docx) com papel timbrado, presença e quórum de instalação por convocação, procuração com limite por pessoa, votação de simulação, regra pronta por pauta com vagas,
unidade do candidato, botão de WhatsApp e lista de envio em CSV. Estas seções (0 em diante) ainda descrevem o mínimo entregue (v2.45.1), que é um desvio decidido do desenho (link pessoal por unidade).
Fora da conta, como já decidido: criptografia (cifra, urna, BJV, guardiões, assinatura cega, verificador) e revisão externa.
Fonte do que está abaixo: conferência de 10/11/2026 (desenho 18 contra o sistema). Legenda: ✓ existe e fiel · ⚠ existe parcial ou diferente · ✗ não existe.

### Funções do sistema que o desenho não mostra e que NÃO saem

- Escritório liberado pelo Master (`LiberacaoDoVoto`, `GuardaDoMasterDoVoto`, `LiberacaoDoVotoNoEscritorio`) e recurso `voto` no catálogo.
- Link pessoal por unidade (`TokenDaUnidade`, semente + hash, "gerar novo link").
- Envio de links por e-mail em lotes de 25, "faltam N", reenviar (`EnviarLinksPorEmailUseCase`).
- "Copiar mensagem" (WhatsApp) por unidade; remover unidade (`voto_assembleia_unidade_remover`).
- `PapelDoContainer` (404 de rotas públicas no BlueJus, preparação do container voto), `SaudeDoVotoController` (`/saude-do-voto`), purga do schema `voto` e redes do schema.
- Limitador `voto_cedula_invalido`, cabeçalhos noindex/no-store, filtro de tenant desligado na cédula (`CedulaSemTenantFilterListener`).
- Encerramento automático por prazo vencido (`EncerrarPautaPorPrazoVencidoUseCase`).
- Aviso de sigilo na cédula ("o sistema registra que a unidade votou, mas não em quê").

### Etapas e tamanho (P = até 1 dia, M = 2 a 3 dias, G = 4 ou mais)

| # | PR | Entrega | Tam. | Migration | Decisão do dono? |
|---|---|---|---|---|---|
| 0 | `voto-desenho-novo` | copiar o desenho mais novo para `docs/design/`, atualizar a spec com os itens novos | P | não | Farlei envia o desenho oficial? |
| 1 | `voto-regras-pauta` | critério (4), base (3), quórum por pauta, regra pronta, dígitos, Aprovada/Rejeitada; `ApuracaoDaPauta` e ata | M | sim | D1 |
| 2 | `voto-assistente-completo` | 7 passos com trilho, Categoria/campos do passo 1, Revisar com pendências, "Alterações não salvas" | M | sim (campos da assembleia) | D2 |
| 3 | `voto-lista-aptos` | upload CSV/XLSX, "Baixar modelo", data da importação, busca | M | pode ser sem | D3 |
| 4 | `voto-link-qr` | link do evento, QR no servidor, cartaz PNG, código de conferência, WhatsApp e CSV de envio | M | pode ser sem | D4 |
| 5 | `voto-painel-mesa` | KPIs, passos 1-6, incidentes, presença, quórum de instalação, "abrir mesmo assim" | G | sim | D5 |
| 6 | `voto-ata-docx` | minuta .docx com timbre; ata PDF/HTML com responsáveis, incidentes, código | M a G | não | D6 |
| 7 | `voto-cabine` | cabine de urna, nulo, "Próxima votação", simulação, sons, braile, leitor de tela | G | pode ser sem | D7 |
| 8 | `voto-comprovante` | BJC, imagem/PDF, e-mail, conferência pública | M | sim | D8 |
| 9 | `voto-identificacao` | Localizar nome + código por e-mail + limite | G | sim | D9 |
| 10 | `voto-procuracao` | procuração, instrumento anexo, limite | M | sim | D10 |
| 11 | `voto-horario` | programado no horário + sigilo aberto | M | sim | D11 |
| 12 | `voto-sessao-permanente` | próxima sessão, retomar, troca de voto, ata parcial | G (muda o modelo de voto) | sim | D12 |
| 13 | `voto-equipe` | Equipe/perfis, Estatísticas Master, Auditoria simples | G | sim | D13 |
| 14 | `voto-cenarios` | demais 8 categorias, modalidades chapa/individual/consulta, vagas, foto/propostas | G | sim | D14 |

Ordem recomendada para "falta muita coisa" em produção: 0, 1, 2, 3, 4, 6, 5, 7, 8, depois 9 a 14 conforme o dono pedir.
Estimativa total do que falta (sem cripto): cerca de 40 a 50 dias de sessão, com os grandes (5, 7, 9, 12, 13, 14) ocupando ~25.
Uma migration por vez entre PRs abertos: 1, 2, 5, 8 entram em fila.


### Conferência por etapa do desenho (o que existe e o que falta)

#### E1. Moldura e abas

| Item do desenho | Estado | Prova / diferença |
|---|---|---|
| Título "Voto" e abas Votar / Resultados / Gestão eleitoral / Acompanhar (`.vTab`) | ✓ | `app/templates/voto/index.html.twig:29-50` |
| Aba Votar do sistema lista votações publicadas | ⚠ | aba mostra só aviso "vazio_gestao" (`index.html.twig:50-53`); o morador entra por link, não por esta aba |
| Sub-abas da Gestão: Painel, Criar eleição, Lista de aptos, Apuração | ⚠ | viraram páginas separadas (`/voto/assembleias/nova`, `/{id}/pautas`, `/{id}/unidades`); sem a barra de sub-abas (`_sub_abas.html.twig` existe só como trilho do assistente) |
| Sub-abas Estatísticas, Perfis, Eleitores, Avaliações, Auditoria, Testes; "Atuando como" | ✗ | nenhuma rota (grep `debug:router`/Controllers) |
| Barra de contexto/escritório e papéis por votação (Comissão, Auditoria, Fiscal, Guardião...) | ✗ | quem tem o módulo `voto` faz tudo (`GuardaDaEquipeDoVoto.php`) |
| Toast do desenho | ✓ | `_toast.html.twig`, `AvisosDoVoto.php` |
| Responsivo 760px | ⚠ | `css/voto.css` existe; não conferido item a item (é conferência de tela do Samuel) |

#### E2. Assistente de criação (7 etapas)

| Etapa / campo | Estado | Prova / diferença |
|---|---|---|
| Trilho de 7 passos "1. Categoria ... 7. Revisar", clicar vai à etapa | ⚠ | só 3 telas: Evento+Peso (`form.html.twig`), Pautas (`pautas.html.twig`), Unidades e links (`unidades.html.twig`); sem trilho clicável nas 7 |
| 1 Categoria: 9 cenários prontos (`.vCats`) | ✗ | só condomínio, fixo; nenhum seletor de categoria |
| 1 Campos Entidade, Nome da votação, Descrição, Finalidade | ⚠ | só `titulo` (evento) (`AssembleiaInput`, `form.html.twig:25`) |
| 2 Modalidade (chapa/individual/pauta/consulta/institucional) | ✗ | só pauta Sim/Não/numerada |
| 2 Peso do voto (1 por eleitor / com peso da lista) | ✓ | `PesoDoVoto` fracao/unidade, `form.html.twig:26-29` |
| 2 Abertura e encerramento: programados no horário x conduzidos pela mesa | ✗ | só mesa (sem Início/Encerramento, sem agendador de abertura) |
| 2 Alterar voto em sessão permanente | ✗ | não existe |
| 2 Sigilo (secreto / aberto após / aberto em tempo real) | ⚠ | só secreto prático (`voto-assembleia-condominio.md` §2); aberto/nominal ✗ |
| 2 Grupos aptos; Ordem das candidaturas (registro / sorteada) | ✗ | |
| 2 Quórum de instalação 1ª/2ª conv., limite de procurações, "Votação de simulação" (novos) | ✗ | |
| 3 Acesso: PIN / código por e-mail | ✗ | (desvio decidido: link da unidade); "Localizar nome", código de 6 números, 5 tentativas, foto e art. 299 não existem |
| 4 Atalhos "Cargos e pautas frequentes" | ✓ | `pautas.html.twig:22-30` |
| 4 Cargo/pauta + opções Nº e nome, "Candidatura", remover, "Outro cargo ou pauta", validações de texto | ✓ | `_pauta_form.html.twig`, `OpcoesDaPauta.php:35-39` |
| 4 Dígitos (1 a 5) | ✓ | PR 5: select e maxlength por pauta; trocar não renumera |
| 4 Critério (Mais votos / Maioria absoluta / Dois terços / Unanimidade) | ✓ | PR 5: select; apuração publicada na v2.49.0 |
| 4 "Calculado sobre" (válidos / presentes / total de aptos) | ✓ | PR 5: select com as três bases |
| 4 Quórum (%) e Quórum 2ª conv. por pauta | ✓ | PR 5: editáveis em rascunho; segunda vazia herda primeira |
| 4 Regra pronta, Vagas (várias vagas), "Numerar" | ✓ | PR 5: presets, 1 a 9 vagas e sequência com zeros |
| 4 Unidade do candidato, ↑↓ reordenar, descrição/propostas/composição/foto da opção | ✗ | frente posterior ao PR 5 |
| 5 Lista de aptos: importar planilha XLSX/CSV, "Baixar modelo", contagem aptos/não aptos | ⚠ | só colar texto em textarea (`unidades.html.twig:23`, `LeitorDaListaDeUnidades.php`); sem upload de arquivo, sem XLSX, sem modelo; resumo "N aptos, N inaptos" ✓ (`:30`) |
| 5 Cabeçalhos aceitos (unidade, bloco, fração, situação...), apto só se apto/adimplente/regular/quite, peso em vírgula, duplicadas/inválidas, bloqueio após abertura | ✓ | `LeitorDaListaDeUnidades.php`, `ImportarUnidadesUseCase.php`, `unidades.html.twig:27,37-40` |
| 5 Hash da lista, data da importação, lista por nome/e-mail do eleitor | ✗ | hash só serve à urna; "Importada em" ✗ |
| 6 Equipe (perfis, vagas, "Indicar pessoas") | ✗ | |
| 7 Revisar: tabela com "Alterar", pendências com ícones, "Tudo pronto para publicar" | ✗ | publicar vai direto de Unidades (`unidades.html.twig:104`, `PublicarAssembleiaUseCase`); exige 1 pauta com 2 opções e 1 unidade apta |
| Salvar e sair / Voltar / Continuar; erro da etapa; rascunho salvo a cada avanço | ✓ | `form.html.twig:34-39`, `pautas.html.twig` |
| "Alterações ainda não salvas" | ⚠ | só aviso do navegador nas pautas (`voto-assembleia.js`); sem texto no cabeçalho |
| "Votação de simulação" + reiniciar/apagar | ✗ | |

#### E3. Publicar, link e QR

| Item | Estado | Prova |
|---|---|---|
| Publicar trava a configuração | ✓ | `voto_assembleia_publicar`, `Assembleia::publicar` |
| Link pessoal por unidade, copiar, mensagem pronta, gerar novo, reenviar | ✓ (desvio) | `unidades.html.twig:74-90`, rotas `voto_assembleia_unidade_*` |
| Enviar links por e-mail em lotes de 25, "faltam N" | ✓ (extra do sistema) | `EnviarLinksPorEmailUseCase`, `voto_assembleia_link.html.twig` |
| Janela "Link e QR Code" (link do evento, QR, cartaz PNG, "Copiar link", "Testar acesso", "Abrir terminal presencial", código de conferência XXXX-XXXX) | ✗ | nenhum QR (grep `qrcode` vazio); link único do evento não existe |
| Botão WhatsApp e "Lista para envio" CSV | ⚠ | só copia a mensagem (`unidades.html.twig:75`); sem `wa.me` nem CSV |
| Cartão "Eventos com mais de uma votação" (link do evento + relatório consolidado) | ⚠ | a assembleia já é o evento; sem link/QR do evento |

#### E4. Eleitor (cédula, cabine, comprovante)

| Item | Estado | Prova |
|---|---|---|
| Portal sem conta, cabeçalho, trilho 1-4, faixa de conferência do endereço e código | ⚠ | `cedula/_layout.html.twig`; sem trilho de passos e sem código de conferência |
| Localizar seu nome / Confirmar identidade / código por e-mail / "Não sou eu" / limite 20 em 10 min | ✗ | (desvio decidido) token por unidade; limitador só para token inválido (20/min por IP) |
| Cartão da pauta: nome, descrição, chip, "Aguardando abertura", "Encerra em mm:ss", "Você já votou", inapta | ✓ | `cedula/_pauta.html.twig:21-33` |
| Votar: escolher opção ou abstenção + confirmar | ⚠ | botões e modal de confirmação (`cedula.html.twig:38-46`), no lugar da CABINE |
| Cabine de urna (visor, teclado 0-9, BRANCO/CORRIGE/CONFIRMA, LEDs, sons, braile, teclado físico, leitor de tela, `prefers-reduced-motion`) | ✗ | |
| Nulo ("NÚMERO ERRADO / VOTO NULO"), branco = abstenção | ⚠ | abstenção ✓; nulo ✗ (a ata imprime "Nulos 0" fixo, `ata.html.twig:119`) |
| Estados uInicio/uVota/uGrava/uFim/uErro, "Próxima votação" | ✗ | cédula mostra todas as pautas numa página |
| Conhecer candidaturas, Simular votação | ✗ | |
| Comprovante de participação (BJC, imagem/PDF, QR, 2ª via com faixa) | ✗ | grep `comprovante` vazio |
| Enviar comprovante por e-mail | ✗ | (o sistema tem e-mail; só falta o modelo) |
| Conferir comprovante (aba Resultados) | ✗ | |
| Resultado para o morador após encerrar | ✓ | `cedula/_resultado.html.twig` |
| Voto único, abstenção, peso, sem ligação unidade/opção | ✓ | `RegistrarVotoUseCase`, `OpcaoDaPautaRepository`, migration `Version20261109170000` |

#### E5. Mesa, painel, acompanhar

| Item | Estado | Prova |
|---|---|---|
| Abrir por minutos / sem prazo, 1ª/2ª convocação, prazo, "Sem prazo", encerrar com motivo, auto-encerra | ✓ | `_pauta_linha.html.twig`, `MesaDaAssembleiaController`, `EncerrarPautaPorPrazoVencidoUseCase` |
| Painel: barra e "Participação {n} de {aptos} aptos ({pct}%)" | ✓ | `_pauta_linha.html.twig:13-14` |
| Painel: KPIs (Eleitores habilitados, Em análise, Votações abertas, Cédulas, Incidentes) | ✗ | |
| Painel: passos 1-6 por pauta e "próximo passo" com atalho | ✗ | |
| Painel: "Incidentes registrados" | ✗ | |
| Presença registrada, quórum de instalação, "Abrir mesmo assim" (novo) | ✗ | |
| Procuração por unidade e limite (novo) | ✗ | |
| Ocorrência por unidade / Avaliações do conselho | ✗ | |
| Sessão permanente (próxima sessão 60/90 dias, retomar, trocar voto, ata parcial) | ✗ | |
| "Iniciar apuração" | n/a | sem guardiões o resultado sai no encerramento (decisão do mínimo) |
| Acompanhar: lista por pauta, "N de M votaram", chip, Assistir em janela própria | ✓ | `_acompanhar.html.twig`, `assistir.html.twig:28-44` |
| Assistir: % do peso, barra e marca do quórum, atualiza sozinha | ✓ | `assistir.html.twig:30-46` |
| Voto aberto/nominal, lista nominal, documento de auditoria | ✗ | |

#### E6. Resultados e ata

| Item | Estado | Prova |
|---|---|---|
| Aba Resultados por pauta encerrada: faixa, KPIs, barras, abstenções, decisão | ✓ | `_resultados.html.twig` |
| Situações "Decidido / Quórum não atingido / Empate / Critério não atingido" | ⚠ | `ApuracaoDaPauta.php`; só critério "Mais votos"; sem dois terços/absoluta/unanimidade, sem vagas |
| Resultado "Aprovada / Rejeitada" por regra pronta (novo) | ✗ | |
| Ata/relatório consolidado: resumo, identificação, abertura, eleitorado por bloco, encerramento, apuração, relação de votantes, termo, assinaturas | ⚠ | HTML imprimível (`ata.html.twig`), no lugar do PDF A4 com `BJR-...`, QR e página X de Y; faltam: Responsáveis designados, Hash da configuração, Incidentes, código/QR |
| Minuta de ata em Word (.docx) com papel timbrado (novo) | ✗ | nenhum gerador de .docx em `app/src/Voto` |
| Ata parcial (sessão permanente) | ✗ | |
| Relação de votantes (nominal, faixa horária, após encerrar) | ⚠ | existe na ata (`ParticipacaoNaPautaRepository::listarNominalDaPauta`); sem a regra "só perfis autorizados" nem faixa de 1 hora |
| Inconsistência do desenho: "Resultados" x "Transparência" | n/a | usar "Resultados" |

#### E7. Testes e registros

| Item | Estado |
|---|---|
| Aba Testes (97 testes do protótipo, gerar votos de teste) | ✗ (só testes PHPUnit do sistema, `app/tests/Voto/`) |
| Trilha de auditoria com Verificar/Exportar | ✗ (cripto/cadeia; fora) / trilha simples ✗ |
| Estatísticas do Gestor Master, Perfis, Eleitores/Validação, Avaliações do conselho | ✗ |
| Cenários não condomínio (classe, associação, sindicato, cooperativa, ensino, consulta) | ✗ |


### 2. Regra de negócio / dado x só tela

Só tela (dado já existe): trilho de 7 passos; revisão (7); KPIs e passos do painel; "Alterações ainda não salvas"; botão WhatsApp com `wa.me`; link do evento (a assembleia já é o evento); janela de link com QR (gerar QR no servidor, lib PHP); cartaz; cabine (teclado/visor sobre o mesmo POST de voto); trilho do portal.
Regra de negócio / dado novo (precisa migration e UseCase):
- Critério, base de cálculo, quórum por pauta, dígitos, regra pronta, vagas, empate por vaga (campos novos em `voto.assembleia_pauta`; `ApuracaoDaPauta` reescrito).
- Quórum de instalação por convocação + presença registrada + "abrir mesmo assim" (tabela de presença; migration).
- Procuração e limite por pessoa (tabela; instrumento é arquivo: skill `armazenamento-de-arquivos`).
- Sessão permanente e troca de voto (modelo de voto muda: hoje não há vínculo unidade-opção, trocar voto exige guardar o voto por unidade; CONFLITA com o sigilo prático da §2 da spec do mínimo).
- Votação programada no horário (Início/Fim + tarefa agendada; hoje só mesa).
- Sigilo aberto/nominal (guardar quem votou em quê; só se a entidade permitir).
- Importação por arquivo CSV/XLSX, modelo e data da importação (XLSX exige biblioteca; checar `composer.json`).
- Identificação forte: "Localizar nome" + código por e-mail + limite 20/10 min (e-mail já existe, `LinkDaUnidadeMailer`); fotos/documento/geo é outro peso.
- Ata em .docx com timbre (biblioteca de Word; o desenho já traz o algoritmo em `bj-voto-ata.js`: gera zip+XML na mão, 6 KB).
- Simulação (flag na assembleia, reiniciar/apagar, fora das estatísticas).
- Comprovante (código BJC, imagem/PDF, e-mail, conferência pública).
- Equipe/perfis por votação (Comissão, Auditoria...).


### 5. Perguntas de negócio D1 a D14 (decisão do dono, ainda aberta)

- D1. Entram já os critérios "Maioria absoluta", "Dois terços" e "Unanimidade" por pauta? (sim/não) Decisão do dono, ainda aberta.
- D2. A assembleia terá sempre as 7 etapas, ou só as que importam para condomínio (pular Categoria, Acesso e Equipe)? (7 / reduzido) Decisão do dono, ainda aberta.
- D3. Aceitar XLSX além de CSV? (sim/só CSV) Decisão do dono, ainda aberta.
- D4. O QR Code leva a um link único do evento (todos os moradores digitam nome e unidade) ou continua o link pessoal por unidade? (evento / pessoal / os dois) Decisão do dono, ainda aberta.
- D5. Quórum de instalação: abrir abaixo dele só com decisão registrada na ata? (sim/não) Decisão do dono, ainda aberta.
- D6. Ata: gerar .docx com papel timbrado do escritório (modelo enviado pelo gestor), além do HTML? (sim/não) Decisão do dono, ainda aberta.
- D7. Cabine de urna estilo urna eletrônica (com sons e braile) é pedida pelo condomínio, ou fica o botão com confirmação atual? (cabine/atual) Decisão do dono, ainda aberta.
- D8. Comprovante de participação (sem o voto) para o morador: entra? (sim/não) Decisão do dono, ainda aberta.
- D9. Identificação por "nome + unidade + código de e-mail" substitui o link pessoal? (substitui/convive/não) Decisão do dono, ainda aberta.
- D10. Procuração: limite padrão por pessoa? (0 = sem limite, ou número) Decisão do dono, ainda aberta.
- D11. Voto aberto/nominal é permitido para condomínio? (sim/não); programar abertura por horário entra? (sim/não) Decisão do dono, ainda aberta.
- D12. Sessão permanente e troca de voto (art. 1.353): entram? Obriga guardar quem votou em quê, o que quebra o sigilo atual. (sim/não) Decisão do dono, ainda aberta.
- D13. Perfis (Comissão, Auditoria, Fiscal) por votação entram, ou basta quem tem o módulo `voto`? (perfis/basta) Decisão do dono, ainda aberta.
- D14. Outras categorias (classe, associação, sindicato, cooperativa, ensino) entram agora ou só condomínio? (agora/depois) Decisão do dono, ainda aberta.


### 6. Totais (itens contados na tabela da seção 1)

✓ 18 · ⚠ 17 · ✗ 40 · n/a 2 (uma contagem por linha de tabela; linhas com vários campos contam uma vez).

---

## 0. Leia antes (decisões que o desenho força)

1. **O desenho não tem `data-screen-label`.** As "telas" são abas (`sc-if` por `aba`/`sub`) e janelas (`modTem`, `lnkTem`, `cabTem`).
2. **Assembleia = EVENTO; cada pauta = uma VOTAÇÃO.** No dado de exemplo do desenho (`semente`, L321) a assembleia
   "Assembleia Geral Ordinária (exemplo)" é um `eventoNome` com 3 votações separadas (`cond-contas`, `cond-orcamento`,
   `cond-sindico`), cada uma com **1 pauta** (1 `cargo`). É isso que permite à mesa "abrir e encerrar pauta" um a um
   (os botões da mesa agem na votação inteira, L194/L395). Votações com o mesmo nome de evento ganham link único,
   voto em sequência ("Próxima votação" na cabine) e relatório conjunto (ESPEC §15). O assistente também aceita várias
   pautas numa votação só ("Outro cargo ou pauta"); nesse caso todas abrem e fecham juntas e a cabine passa por todas.
   Recomendação de modelo: `Assembleia(evento) 1:N Pauta(votação, 1 cargo) 1:N Opção`, lista de unidades por assembleia.
3. **Data da assembleia não aparece no cenário condomínio.** O cenário vem com `modoFim:'mesa'` e o bloco "Período"
   (Início/Encerramento) só aparece se `modoFim !== 'mesa'` (`edHor`, L213). `novoEd` grava início = amanhã 08:00 e
   fim = +10h por baixo (L348), e o cartão do votante mostra "início até fim" (L150). Para ter "data" no mínimo é preciso
   decidir: mostrar Início/Encerramento também no modo mesa, ou usar "Programados no horário". Desvio: decidir antes.
4. **Identificação do votante sem biometria não existe pronta no desenho.** Os dois caminhos do desenho exigem foto:
   PIN (cadastro "Cadastro para votar" com documento, selfie e análise humana) e código por e-mail (passo 3 com foto do
   documento, selfie, LGPD e declaração do art. 299). O caminho mais próximo do mínimo: **Localizar seu nome** (nome
   completo + Unidade) e **código de 6 números por e-mail** (passos `vT1`/`vT2`), pulando `vT3` (fotos). Desvio: decidir.
5. **Textos que prometem criptografia** (não podem ir para uma versão sem criptografia, mesma lição do "login promete
   criptografia"): "Depois daqui, ninguém vê sua escolha: seu voto é cifrado neste aparelho e separado do seu nome."
   (L258), "Protegendo seu voto" / "Cifrando a cédula e separando-a da sua identidade" (L436), "Comprovante de depósito
   da cédula (não revela seu voto)" com código BJV (hash da urna, L271/N143), "Os votos permanecem cifrados: o resultado
   só existe depois da apuração." (L377). Precisam de texto neutro aprovado.
6. **Textos de protótipo**: "Ambiente demonstrativo. Dados e eleitores são fictícios..." (L135), `.uDemo` (L277, L258),
   cartaz e PDFs com "Ambiente demonstrativo...". Decidir se saem no sistema real.
7. **Apuração**: no desenho, "encerrada" só vira "apurada" por guardiões (3 de 5 partes, L240-243). Sem criptografia não há
   guardiões; o resultado pode ser calculado no encerramento ou no botão "Iniciar apuração" da Comissão. Decidir.
8. Inconsistência do próprio desenho: a aba chama "Resultados" (L386) mas os textos dizem "Transparência"
   ("Votação apurada. O resultado está em Transparência.", "Apuração concluída. Veja o resultado em Transparência.").
9. Já existe `app/src/Voto/` e specs `docs/specs/bluejus-voto*.md` no repositório (não li; conferir antes de modelar).

## 1. Base visual (vale para tudo)

- Fonte do body: `font-family:Arial, Helvetica, sans-serif`; `background:#f3f6f8`; `color:#12242f` (L26).
- Ícones: **Bootstrap Icons** (desenho com v1.11.3 local; o BlueJus já carrega 1.13.1 em `base.html.twig`), classe `bi bi-<nome>`, só contorno salvo onde indicado `-fill`.
- QR Code: `qrcode-generator@1.4.4` (cdn.jsdelivr), nível `'M'`, módulos `#0b3550`, margem 4 módulos, fundo `#fff` (L309).
- O desenho **não define variáveis CSS**. Tokens sugeridos (valores do desenho):
  `--v-azul:#0a6fa0` (primário, cabeçalho de cartão, aba ativa) · `--v-azul-hover:#3b8fc2` (hover e foco) ·
  `--v-marinho:#0b3550` (títulos de cartão, KPI) · `--v-texto:#12242f` · `--v-texto2:#34505f` · `--v-mudo:#5b7383` ·
  `--v-rotulo:#4a6373` · `--v-borda:#d3dde4` · `--v-borda2:#e6edf2` / `#e3ecf2` · `--v-zebra:#f6f9fb` · `--v-fundo:#f3f6f8` ·
  `--v-verde:#1e7b45` · `--v-vermelho:#a3232b` · `--v-ambar:#8a6200` · `--v-aviso-bg:#fff8e6` / `--v-aviso-borda:#e6c56a` / `--v-aviso-tx:#6b4e00`.
- Raio: 3px em quase tudo (cabine: corpo 14px 14px 10px 10px, teclado 2px/3px).
- Status (L287, chip `.vChip` com `background` = cor): Rascunho `#5b7383` · Publicada `#0a6fa0` · Votação aberta `#1e7b45` ·
  Encerrada `#8a6200` · Apurada `#0b3550`.
- Toast (L281, inline): `position:fixed; bottom:24px; left:50%; transform:translateX(-50%); z-index:6000; color:#fff;
  padding:11px 18px; border-radius:3px; font-size:14px; font-weight:bold; box-shadow:0 8px 22px rgba(0,0,0,.25); max-width:90vw;`
  fundo `#1e7b45` (sucesso, padrão) ou `#a3232b` (erro); some em 3600 ms; `role="status"` (L319).
- Página: `h1` "Voto" `27px normal #12242f`; `main` `max-width:1240px; padding:18px clamp(10px,2vw,24px) 110px; gap:12px` (L133-134).
- Abas do topo (L386): "Votar" `bi-check2-square` · "Resultados" `bi-shield-check` · "Gestão eleitoral" `bi-gear` ·
  "Acompanhar" `bi-broadcast` (as duas últimas só para gestão, nunca no portal). Componente `.vTab` dentro de `.vC` com `display:flex; overflow-x:auto`.
- Sub-abas da Comissão (L387-388): "Painel" `bi-speedometer2` · "Criar eleição" `bi-plus-square` · "Lista de aptos" `bi-list-check` ·
  "Apuração" `bi-calculator`.
- Responsivo: `@media (max-width:760px)` (cabine em 1 coluna, sem LEDs, `.uTela` 300px, `.vG2` 1 coluna, `.vOc` some);
  `@media (prefers-reduced-motion: reduce)` corta animações da cabine.
- Componentes do desenho (nunca Bootstrap/AdminLTE): cartão `.vC`, faixa de título `.vH`, botão `.vB` (+`.vP` primário,
  `.vD` perigo), rótulo-campo `.vL` + `.vF`, abas `.vTab`/`.vTabOn`, grade `.vG2`, KPIs `.vKpis`/`.vKpi`, selo `.vChip`,
  linha `.vLin`, passos `.vPas`/`.vPasOk`/`.vPasAt`, seção `.vSec`, cartões de categoria `.vCats`/`.vCat`/`.vCatOn`,
  aviso `.vAviso`, monoespaçado `.vMono`, janela `.vMod`/`.vModC` (com `.vH` + botão "Fechar"; clique no fundo fecha; o
  conteúdo para a propagação). CSS em §7.

## 2. (a) Gestor cria a votação (cenário condomínio)

Onde: aba "Gestão eleitoral" > sub-aba "Criar eleição" (`subEl`, L206-229). Entrada também pelo botão "Criar eleição"
(`vB vP`, `bi-plus-lg`) na barra do topo da gestão (L189). Sem rascunho aberto (`edSem`): texto "Um assistente conduz a criação
em 7 etapas. Você pode parar e continuar depois: cada etapa fica salva como rascunho." + botão "Criar eleição".

Cabeçalho `.vH` com `bi-plus-square` e título `edTitulo`: "Criar eleição" ou "Rascunho: {nome}" / "Rascunho: sem nome" (L431).
Trilho de passos (`edPasL`, botões `.vPas` com `border:0; font-size:13px; padding:6px 10px; cursor:pointer`; feitos `.vPasOk`,
atual `.vPasAt`; clicar vai à etapa validando as anteriores): "1. Categoria" "2. Regras e prazo" "3. Acesso" "4. Cargos"
"5. Lista de aptos" "6. Equipe" "7. Revisar". Título da etapa `b 18px #0b3550` + subtítulo `13.5px #34505f` (L211):

| Etapa | Título | Subtítulo |
|---|---|---|
| 1 | Que tipo de votação você vai criar? | A categoria já traz regras, documentos e cargos prontos. Tudo pode ser ajustado. |
| 2 | Regras e prazo | Defina como o voto funciona e quando a votação acontece. |
| 3 | Como o votante entra | A configuração sugerida atende à maioria dos casos. |
| 4 | Cargos e candidaturas | Inclua cargos, pautas ou perguntas e suas opções. |
| 5 | Quem pode votar | Importe a relação de quem está apto. |
| 6 | Quem conduz a votação | Escolha quem vai operar, apurar e fiscalizar. |
| 7 | Confira e publique | Depois de publicada, a configuração fica bloqueada. |

Rodapé (L227, `border-top:1px solid #e3ecf2; padding-top:12px; gap:8px`): "Salvar e sair" (`.vB`) | espaçador |
"Voltar" (`bi-arrow-left`, a partir da etapa 2) | "Continuar" (`vB vP`, ícone `bi-arrow-right` DEPOIS do texto, até a 6) |
"Publicar votação" (`vB vP`, `bi-megaphone`, só na 7, desabilitado se houver pendência ou se o perfil não for Comissão).
Erro da etapa: `color:#a3232b; font-size:13.5px; font-weight:bold` (`edErro`, L226). Cada avanço grava o rascunho (L360).

**Etapa 1** (L212): grade `.vCats` com 9 `.vCat` (ícones: condomínio `bi-building`, classe `bi-award`, associação `bi-people`,
sindicato `bi-megaphone`, cooperativa `bi-diagram-3`, sociedade `bi-briefcase`, ensino `bi-mortarboard`, consulta
`bi-chat-square-text`, personalizado `bi-sliders`); o assistente já nasce em **condomínio** (`novoEd`, L348). Aviso `.vAviso` com a
ajuda do cenário: "Votos por fração ideal, salvo disposição diversa da convenção. Confira na convenção o quórum de cada pauta,
a segunda convocação e quem está apto a votar." Campos `.vG2`: "Condomínio" (rótulo do cenário), "Evento (use o mesmo nome
para agrupar votações)", "Nome da votação" (linha inteira), "Descrição" (textarea, linha inteira), "Finalidade".
Validação: "Informe a entidade." / "Informe o nome da votação.".

**Etapa 2** (L213) `.vG2`: "Modalidade" (Eleição por chapa / Eleição individual / **Pautas de assembleia** / Consulta / Votação
institucional) · "Peso do voto" (Um voto por eleitor / **Com peso da lista (fração, quotas)**) · "Abertura e encerramento"
(Programados no horário / **Conduzidos pela mesa**) · se mesa: "Alterar voto em sessão permanente" (Permitir até o encerramento /
Não permitir) · "Sigilo do voto" (Secreto / Aberto: nominal, visível após o encerramento / Aberto: nominal, visível em tempo real)
· se não for mesa: `.vSec` "Período" + "Início" + "Encerramento" (`datetime-local`) · `.vSec` "Quem participa" + "Grupos aptos
(separados por vírgula; vazio para todos)" + "Ordem na lista de candidaturas" (Ordem de registro / Sorteada a cada visualização).
Em negrito = padrão do condomínio (`bj-voto-cenarios.js`). Validação (só modo horário): "Confira o início e o encerramento." /
"O início precisa ser uma data futura. Para abrir na hora, escolha Conduzidos pela mesa.".

**Etapa 4: pautas e opções** (L215-222). Linha de atalhos: "Cargos e pautas frequentes:" + um `.vB` (`bi-plus`) por item:
"Síndico(a)", "Subsíndico(a)", "Conselho fiscal", "Aprovação de contas", "Previsão orçamentária", "Obra ou benfeitoria",
"Alteração da convenção" (cada um cria a pauta padrão com esse nome; se só houver a pauta vazia inicial, substitui).
Cada pauta: caixa `border:1px solid #d3dde4; border-left:4px solid #0a6fa0; padding:12px; gap:10px` com
"Cargo, pauta ou pergunta" · "Dígitos" (1 a 5) · "Critério" (Mais votos / Maioria absoluta / Dois terços / Unanimidade) ·
"Calculado sobre" (Votos válidos / Presentes / Total de aptos) · "Quórum (%)" · "Quórum 2ª conv." · botão "Candidatura"
(`bi-person-plus`) · botão `vB vD` `bi-trash` (aria "Remover cargo"). Cada opção: faixa `background:#f7f9fb; padding:9px` com
"Nº" (90px) + "Nome da chapa ou candidatura" + "Descrição" + "Propostas" + "Composição da chapa (um cargo por linha, ex.:
Presidente: Nome)" + foto (`.vAv`, "Enviar foto"/"Trocar foto") + botões ↑ `bi-arrow-up` / ↓ `bi-arrow-down` / `vD bi-trash`.
Abaixo: "Outro cargo ou pauta" (`bi-plus-lg`).
**Pauta padrão do condomínio**: `{ dig:1, regra:'mais', base:'validos', quorum:50, quorum2:0, cands:[{num:'1',nome:'Sim'},{num:'2',nome:'Não'}] }`.
Para pauta Sim/Não, os campos Foto, Propostas e Composição ficam vazios (são de chapa); ver §6.
Validação (L359, N110): "Informe o nome do cargo, pauta ou pergunta." · "Inclua ao menos um cargo." · "Inclua candidaturas em
{pauta}." · "Número {n} deve ter {dig} dígito(s)." · "Número repetido em {pauta}." · "Candidatura sem nome em {pauta}.".

**Etapa 5: unidades com fração** (L223): texto "Envie planilha XLSX ou CSV. Quem não consta na lista não vê a votação." ·
`label.vB.vP` com input file (`.csv,.txt,.xlsx,text/csv`) e `bi-upload` "Importar lista" / "Substituir lista" · "Baixar modelo"
(`bi-download`) · linha de estado `14px bold`: "{a} aptos e {i} não aptos importados." (`#1e7b45`) / "Lista obrigatória nesta
votação." (`#a3232b`, quando há peso) / "Lista opcional. Sem ela, vale a análise de cada cadastro." (`#5b7383`).
Toast: "{total} registros importados. Inválidas: {n}. Duplicadas: {n}.". Validação: "Esta votação precisa da lista de aptos.".
Modelo (L424, `modelo-lista-de-aptos.csv`, `;`, BOM UTF-8):
```
identificacao;nome;email;grupo;situacao;peso;categoria;ocorrencia
A-101;Nome Exemplo;nome@exemplo.invalid;Bloco A;quite;0,0250;;
A-102;;;Bloco A;inadimplente;0,0250;;Cadastro desatualizado
B-201;;;Bloco B;quite;0,0310;;
```
Cabeçalhos aceitos (sem acento/caixa, `bj-voto-entrada.js` L46): ident = identificacao|identificador|inscricao|numero|matricula|
registro|carteira|cpf|**unidade**; grupo = grupo|**bloco**|**torre**|...; situacao = situacao|status|aptidao; peso =
peso|**fracao**|**fracaoideal**|quotas|cotas; nome = nome|nomecompleto; email = email|correioeletronico.
Regras de importação (N97): sem coluna situação todos ficam aptos; com ela só `apto|apta|adimplente|regular|quite` votam
(inadimplente fica na lista como "não apto"); peso decimal com vírgula, guardado como inteiro × 1.000.000 (`PESO1`), vazio = 1;
linha sem identificação ou peso ≤ 0 = inválida; mesma (grupo, identificação) repetida = duplicada; lista bloqueada depois da
abertura ("A lista fica bloqueada depois da abertura da votação."); "Nenhuma linha válida na lista.". Também aceita lista na
sub-aba "Lista de aptos" (L202), com texto: "Envie planilha XLSX ou CSV; use Baixar modelo. Quem não consta na lista não vê a
votação. Sem a coluna situacao, todos ficam aptos; com ela, só "apto", "adimplente", "regular" ou "quite" liberam o voto.
Votação com peso exige a coluna peso. Acesso por código exige nome e email. A lista fica bloqueada após a abertura." e resumo
"{a} aptos, {i} inaptos, peso total {fp}. Importada em {data}." + "Hash da lista: ..." (vMono 11.5px `#7a8e9b`) + aviso de peso
raro ("Atenção: {n} valor(es) de peso com menos de 3 eleitores podem permitir identificar o voto ao abrir as cédulas." só faz
sentido com cédula cifrada).
Rótulos do cenário (`rot`): entidade "Condomínio", identificação "Unidade", grupo "Bloco ou torre", assinaturas da ata
"Presidência da assembleia" e "Secretaria da assembleia".

**Etapa 7: revisar** (L225): tabela em `border:1px solid #d3dde4` de `.vLin` (`13.5px`; rótulo `flex:0 0 150px #5b7383`;
valor em `b`; botão "Alterar" volta à etapa): Categoria, Entidade, Votação, Evento ("Votação única" se vazio), Modalidade, Peso
("Com peso da lista" / "Um voto por eleitor"), Sigilo, Quando ("Conduzida pela mesa"), Acesso, Cargos ("{pauta} ({nº opções})"),
Lista de aptos ("{a} aptos, {i} não aptos" / "Sem lista"), Equipe. Pendências: `13.5px bold`, `bi-x-circle-fill #a3232b`
(bloqueia), `bi-exclamation-triangle-fill #8a6200`, ou "Tudo pronto para publicar." `bi-check-circle-fill #1e7b45`.
Bloqueio de publicação útil ao mínimo (L358): "Antes de publicar, importe a lista de aptos.".

## 3. (b) Publicar e obter link e QR

Publicar: no assistente ("Publicar votação", toast "Votação publicada. Agora divulgue o QR Code." e abre a janela do link) ou no
Painel (botão "Publicar" `vB vP bi-megaphone`, toast "Votação publicada. A configuração ficou bloqueada."). Publicar congela a
configuração e gera `cfgHash` (SHA-256 da configuração pública); "Eleição publicada não pode ser alterada.", "Só rascunho pode ser
publicado.". Painel, próximo passo de publicada no modo mesa: "Divulgue o QR Code. A mesa abre a votação abaixo, quando decidir."
com botão "Mostrar QR Code".

Janela "Link e QR Code" (`lnkTem`, L255): `.vMod` > `.vModC` com `width:min(520px,100%)`, `.vH` `bi-qr-code` "Link e QR Code" +
"Fechar". Corpo `padding:16px; gap:12px`: nome `b 15.5px #0b3550` · "Cada pessoa vota do próprio celular, sem conta no BlueJus:
aponta a câmera, localiza o nome e confirma a identidade." (`13.5px #34505f`) · imagem QR (alt "QR Code de acesso à eleição",
`width:min(280px,100%); aspect-ratio:1; align-self:center`) · "Link de acesso" (`.vF.vMono` readonly, seleciona tudo no foco) ·
"Código de conferência: **XXXX-XXXX**" (`b.vMono`) · botões "Copiar link" (`vP bi-link-45deg`, toast "Link copiado."), "Baixar
cartaz com QR Code" (`bi-download`), "Testar acesso" (`bi-box-arrow-in-right`), "Abrir terminal presencial" (`bi-pc-display`) ·
`.vAviso` "O QR Code leva à votação, mas não libera o voto: a identificação continua exigida. Nesta demonstração o endereço ainda
não abre em outro aparelho; use Testar acesso." (a 2ª frase é de protótipo).
Endereços (L291, L312): votação `https://www.bluejus.com.br/voto/e/{id}`; **evento (assembleia)** `.../voto/v/{evento}` (evento =
`ev-` + nome normalizado). Código de conferência: votação = 8 primeiros hex do `cfgHash` em maiúsculas "XXXX-XXXX"; evento = 4
primeiros de cada `cfgHash` concatenados, 8 caracteres. Painel: cartão "Eventos com mais de uma votação" (`bi-collection`) com
"Link e QR Code do evento" (`bi-qr-code`) e "Relatório consolidado" (`vP bi-journal-text`, quando alguma apurada).
Cartaz PNG 1000×1414 (L314): faixa `#0a6fa0` 170px "Vote pelo celular" (bold 72px branco) · nome (bold 40px `#0b3550`) · QR 720px ·
"Aponte a câmera para o código" (bold 42px `#0b3550`) · "Não precisa ter conta no BlueJus" (bold 34px `#1e7b45`) · "1. Localize seu
nome   2. Confirme sua identidade   3. Vote" (30px `#34505f`) · link (26px) · "Confira o código: {cod}" (bold 32px) · aviso
demonstrativo (24px `#8a6200`). Arquivo `cartaz-qrcode-{id}.png`. Erros: "Gerador de QR Code indisponível. Recarregue a página.",
"Link inválido ou eleição indisponível.", "Link inválido ou evento indisponível.". Geração do link vai à trilha ("Link e QR Code gerados").

## 4. (c) Tela do eleitor: portal, cabine e comprovante

**Portal** (aberto pelo link; `portal`, L116): cabeçalho branco `border-bottom:3px solid #0a6fa0; min-height:74px` com logo
`bj-voto-logo.png` 50px, divisor 1×30 `#d3dde4`, "Portal do Eleitor" (`b 18px #0b3550`), selo "Terminal presencial" (modo presencial).
Sem as abas de gestão. Trilho `vPasL` (L138, `.vPas` 13px `padding:5px 10px`): "1. Localizar seu nome" "2. Confirmar identidade"
"3. Votar" "4. Comprovante". Faixa de conferência (L141, `.vC` `border-left:4px solid #0a6fa0`, `bi-shield-check` 20px): "Confira se
o endereço é bluejus.com.br e se o código de conferência é **{cod}**.".
Localizar (`csTem`, L145): "Localize seu nome para votar" (`b 17px #0b3550`) · "Informe seus dados como constam na lista da votação." ·
"Nome completo" · "Unidade" · (se ambíguo) "Bloco ou torre" · "Continuar" (`vP bi-arrow-right`). Erros (`#a3232b` bold): "Informe o
nome completo." · "Há mais de um registro com essa identificação. Informe também o campo abaixo." · "Informe também a identificação
cadastrada." · "Nome não localizado na lista desta votação. Confira os dados ou procure a comissão responsável." · "Muitas
consultas. Tente novamente em alguns minutos." (20 em 10 min). Resultado não apto: linha com chip "Não apto(a)" `#a3232b` +
"Procure a comissão responsável.".
Identidade (`vTokTem`, L142, ver §0.4): "Confirme sua identidade" · "**{nome}**, você está apto(a) a votar." com
`bi-check-circle-fill #1e7b45` e botão "Não sou eu" · "E-mail cadastrado" · "Receber código por e-mail" (`vP bi-envelope-check`) ·
passo 2: "Informe o código de 6 números enviado para {email}. Ele vale por 10 minutos." · "Código" (vMono, 6) · "Voltar" /
"Confirmar código" (`vP bi-shield-check`). Logado (L144): `bi-person-check-fill #1e7b45` + nome + "Sair".
Cartão de cada pauta (L147-153, `.vC` padding 16px): nome `b 19px #0b3550` + descrição + chip de status; linha `13px #34505f` com
`bi-calendar-event` período, `bi-tags` "{Condomínio} · Pautas de assembleia", `bi-geo` "Todos os grupos", `bi-upc` "Código de
conferência {cod}"; mensagem (`13.5px bold`): "Aguardando abertura pela mesa." (`#0a6fa0`) · "Encerra em mm:ss." (`#8a6200`) ·
"Você já votou nesta votação." (`bi-check-circle-fill #1e7b45`) · "Você não está apto(a) para esta eleição." (`#a3232b`) ·
"Votação encerrada, aguardando apuração." · "Votação apurada. O resultado está em Transparência."; botões "Conhecer
candidaturas" (`bi-person-lines-fill`), "Simular votação" (`bi-play-circle`), "Votar" (`vP bi-check2-square`, desabilitado se não
aberta, não apto ou já votou). Depois de votar: "Comprovante em imagem" (`bi-image`) + e-mail "E-mail para receber o comprovante" +
"Enviar por e-mail" (`bi-envelope`). Vazio: "Identifique-se para ver as votações em que você está cadastrado(a)." / "Nenhuma
votação disponível para o seu cadastro. Se você deveria constar na lista, procure a comissão responsável.".

**Cabine** (`cabTem`, L257-280; CSS `.u*` em §7). Tela cheia escura `.uBg` (`role="application"`, aria "Cabine de votação"),
corpo `.uBody` de urna, grade `.uGr`: à esquerda `.uMold` > `.uTela` (visor branco); à direita `.uDir` com placa `.uPlaca`
(`bj-voto-simbolo.png` + "BLUEJUS<br>VOTO" + botão de som `bi-volume-up`/`bi-volume-mute`, aria "Desligar sons"/"Ligar sons") e
teclado `.uTec` (1-9, 0 na coluna 2, cada tecla com braile `.uB6`; aria "Número N") + `.uAc` "BRANCO" (`.uBr`) "CORRIGE" (`.uCo`)
"CONFIRMA" (`.uCf`). Abaixo: 8 LEDs `.uFen` (piscam em ordem aleatória a cada CONFIRMA, 105 ms entre LEDs, 240 ms acesos) e `.uDemo`.
Estados do visor:
- `uInicio`: logo `bj-voto-logo.png` (`width:min(68%,400px)`), nome da pauta, "Aperte **CONFIRMA** para iniciar" (`#149345`),
  botão "Conhecer candidaturas".
- `uVota`: "SEU VOTO PARA" + nome da pauta (`clamp(23px,3.4vw,35px)` bold) + "Número:" com caixas `.uNum` (cursor `.uCur` piscando);
  **modo pauta** (`rot.pauta`): dica `uDica` mostra "1 = Sim     2 = Não" e "Digite o número no teclado ou aperte **BRANCO** para se
  abster.", sem foto. Branco em pauta = "ABSTENÇÃO" (em eleição "VOTO EM BRANCO"). Número inexistente: "NÚMERO ERRADO" / "VOTO NULO".
  Opção achada: nome bold + descrição. Rodapé `.uRod`: "Aperte a tecla:" / "**CONFIRMA** para CONFIRMAR este voto" (`#1d7f42`) /
  "**CORRIGE** para REINICIAR este voto" (`#c75e12`).
- `uGrava`: título + barra `.uProg` animada + subtítulo (textos de cifra, ver §0.5).
- `uFim`: anel `.uAnel` (SVG check verde animado), "FIM" (`clamp(54px,10vw,92px)`), "Voto registrado" (`#1e7b45`), código do
  comprovante (vMono) + "Comprovante em imagem"; se há outra pauta aberta do mesmo evento: "Próxima votação" (`vP bi-arrow-right`)
  + "Pendente: {nome}"; "Concluir" (vira `vP` quando não há próxima).
- `uErro`: `bi-exclamation-octagon` 54px `#a3232b`, "Voto não registrado", mensagem, "Voltar". Mensagens (N125): "A votação não está
  aberta." · "Este eleitor já votou nesta eleição." · "Eleitor não habilitado para esta eleição." · "Voto incompleto.".
- Simulação (`uSim`): faixa `.uFaixa` "SIMULAÇÃO: nenhum voto é registrado"; fim "Simulação concluída", "Nenhum voto foi registrado.",
  "Simular novamente" (`bi-arrow-repeat`). Voto aberto: faixa "VOTO ABERTO: seu nome e seu voto ficarão visíveis".
Teclado físico (L344): 0-9, Enter = CONFIRMA, Backspace/Delete = CORRIGE, B = BRANCO. Leitor de tela `.uSr` `aria-live="assertive"`
(L420): "Voto para {pauta}. Digite o número." · "{opção}. Confirme ou corrija." · "Voto em branco. Confirme ou corrija." ·
"Número errado, voto nulo. Confirme ou corrija." · "Voto registrado. Fim.". Sons (Web Audio, seno; L330): tecla 440 Hz 60 ms;
erro 196 Hz; CORRIGE 294 Hz; CONFIRMA 392+494 Hz; fim 392+494+587 Hz. Regras: CONFIRMA só aceita com todos os dígitos ou BRANCO;
dígito depois de BRANCO é ignorado; BRANCO com dígito digitado dá som de erro (L339-342).
**Comprovante de participação** (código `BJC-XXXX-XXXX-XXXX`, nunca revela o voto; imagem/PDF em canvas, L353 e
`bj-voto-boletim.js` L14): "COMPROVANTE / DE PARTICIPAÇÃO", "ELEIÇÃO", "ELEITOR(A)", "IDENTIFICAÇÃO" ({Bloco} · {Unidade}), "DATA E
HORA DA PARTICIPAÇÃO" (2ª via: "DATA E FAIXA HORÁRIA (2ª VIA)", só a faixa de 1 hora), QR para `{link}/c/{cod}`. Erro: "Comprovante
indisponível para este voto.". Conferência pública na aba Resultados: "Confira seu comprovante" (placeholder "BJV-... ou BJC-..."),
"Conferir" (`bi-search`): "Comprovante de participação válido." / "Comprovante de participação não localizado.".

## 5. (d) Mesa e (e) resultado e ata

**Painel da Comissão** (`subPa`, L191-195): KPIs `.vKpis` ("Eleitores habilitados", "Em análise", "Votações abertas", "Cédulas
depositadas", "Incidentes"). Cartão "Votações" (`.vH bi-activity`), uma `.vLin` por pauta: nome `b 14.5px` + chip de status; passos
`.vPas` "1. Criar" "2. Lista de aptos" "3. Publicar" "4. Votação" "5. Apuração" "6. Relatório"; "próximo passo" (`b`, `bi-arrow-right-circle`,
`13.5px #0b3550`) + botão de atalho; barra 9px `#e6edf2` com preenchimento `#1e7b45`; "Participação {n} de {aptos} aptos ({pct}%).
Conduzida pela mesa."; botões conforme estado (L194/L395):
- publicada + mesa (`pode.mAb`): select "1ª convocação"/"2ª convocação" (150px, aria "Convocação") · minutos (82px, padrão "5") ·
  "minutos" · "Abrir por minutos" (`vP bi-stopwatch`) · "Abrir sem prazo" (`bi-unlock`). Toast "Votação aberta pela mesa.".
- aberta + mesa (`pode.mCt`): cronômetro `b 14px #1e7b45 min-width:150px` ("Encerra em mm:ss" / "Aberta, sem prazo definido") ·
  minutos · "Definir prazo" (`bi-stopwatch`, toast "Prazo definido.") · "Sem prazo" (toast "Prazo retirado."); ao zerar, encerra
  sozinha com motivo "Prazo definido pela mesa".
- aberta (`pode.enc`): campo "Motivo do encerramento antecipado" + "Encerrar" (`vB vD bi-lock`); no modo mesa o motivo vazio vira
  "Determinação da mesa". Toast "Votação encerrada.".
- encerrada (`pode.apu`): "Iniciar apuração" (`vP bi-calculator`) (ver §0.7).
- apurada (`pode.rel`, perfis comissão/auditoria/fiscal): "Relatório consolidado" (`vP bi-journal-text`); "Relação de votantes"
  (`bi-people`) quando há lista nominal. Sempre que publicada: "Link e QR Code" (`bi-qr-code`).
Próximos passos (texto): aberta "Votação em andamento." + "Acompanhar em tempo real"; apurada "Apurada. Emita o relatório consolidado
para a ata e o cartório.". Cartão "Incidentes registrados" (`.vH` com `background:#8a6200`, `bi-exclamation-triangle`; vazio "Nenhum
incidente registrado.").

**Acompanhar** (aba, `abaAco`, L172): `.vH bi-broadcast` "Acompanhar em tempo real"; texto "Clique em Assistir para abrir uma janela
própria, que pode ser levada a outra tela. Assistir mostra participação e quórum e pode ser projetado. ..."; vazio "Nenhuma votação
publicada."; por pauta (abertas primeiro): nome, "{Condomínio} · {n} de {aptos} votaram", chip, "Assistir" (`vP
bi-box-arrow-up-right`). A janela (popup 620×560, ou painel flutuante arrastável se o pop-up for bloqueado: "Janela bloqueada pelo
navegador: libere pop-ups para abrir separada" + "X") mostra (L376-378): faixa azul com nome, entidade e status; 3 números grandes
(40px `#0a6fa0`) "votaram", "aptos", "{pc}% do peso apto" (com peso) / "de participação"; barra 18px `#dfe9f0` com preenchimento
`#0a6fa0` e **marca vermelha `#a3232b` na posição do quórum**; "Quórum atingido: 50%" (`#1e7b45`) ou "Quórum exigido: 50%"
(`#8a6200`); cronômetro; "Atualizado às HH:MM:SS. ..." (ver §0.5). **Nunca mostra parcial de votos** (só no voto aberto em tempo real).
Participação com peso = soma das frações de quem votou ÷ soma das frações aptas; quórum da 2ª convocação usa `quorum2`.
CSS da janela (copiar, L378):
```css
body{margin:0;font-family:Arial,sans-serif;background:#f3f7fa;color:#12242f}.vv{padding:18px}.h{display:flex;gap:10px;justify-content:space-between;align-items:flex-start;background:#0a6fa0;color:#fff;padding:14px;border-radius:3px}.h b{display:block;font-size:20px}.h span{font-size:13px}.h i{font-style:normal;font-size:12px;font-weight:bold;padding:4px 8px;border-radius:3px;border:1px solid #fff}.g{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0}.g div{background:#fff;border:1px solid #d6e3ec;border-radius:3px;padding:14px 6px;text-align:center}.g strong{display:block;font-size:40px;color:#0a6fa0}.g span{font-size:13px;color:#34505f}.b{position:relative;height:18px;background:#dfe9f0;border-radius:3px}.b u{display:block;height:100%;background:#0a6fa0;border-radius:3px;transition:width .4s}.b em{position:absolute;top:-4px;bottom:-4px;width:3px;background:#a3232b}p{font-size:15px;font-weight:bold;margin:12px 0 0;color:#8a6200}p.ok{color:#1e7b45}small{display:block;margin-top:14px;font-size:12px;color:#5b7383;line-height:1.4}
```

**Regra de resultado** (N35-N37, copiar a lógica):
- Totaliza por pauta: cada voto soma 1 em `votos` e a fração do votante em `peso` (sem peso, 1). "BRANCO" = abstenção; número fora
  das opções = nulo. Opções ordenadas por **peso** decrescente, empate de peso pelo número.
- `presentes = peso das opções + peso das abstenções + peso dos nulos`; `quorumPct = presentes × 100 ÷ peso total apto`;
  quórum exigido = `quorum2` se 2ª convocação e `quorum2` definido, senão `quorum` (condomínio: 50% e 0%). `quorumOk = quorumPct ≥ exigido`.
- Base do critério: "validos" = peso das opções; "presentes"; "aptos" = peso total apto.
- **Empate** = 1º e 2º com o mesmo peso: sem vencedor, situação "Empate" (nenhuma decisão automática, nem voto de qualidade).
- Vencedor só se quórum ok, sem empate e peso do 1º > 0; Maioria absoluta: `peso×2 > base`; Dois terços: `peso×3 ≥ base×2`;
  Unanimidade: `peso ≥ base`.
- Situações (texto literal): "Decidido" (mostra "{nº} {nome}"), "Quórum não atingido", "Empate", "Critério não atingido".
Exibição na aba **Resultados** (L174-184): cartão `.vC` com `.vH bi-shield-check` + nome + chip `background:rgba(255,255,255,.2)`;
faixa de resumo `background:#e8f4ec; border-left:4px solid #1e7b45; 16px bold` "{pauta}: {nº nome | situação}"; KPIs "Cédulas na
urna" / "Participações registradas"; por pauta: título `b 15px #0b3550`, uma linha por opção (`13.5px`: "**{nº}** {nome}" em
`flex:0 0 min(46%,300px)`, barra 15px `#e6edf2` com `#0a6fa0` em `{pct}%` do peso válido, valor `b width:128px right` =
"{votos} un · {fração}" com peso), "Abstenções {n} · Nulos {n}" (`12.5px #5b7383`), decisão `13.5px bold` "Resultado: {nº} {nome}"
(`#1e7b45`) ou a situação (`#a3232b`). Fração exibida com até 6 casas, vírgula decimal (`fp`, L294).

**Ata / Relatório consolidado** (L355; PDF A4 desenhado em canvas por `bj-voto-boletim.js`; título "RELATÓRIO CONSOLIDADO DA
VOTAÇÃO"; código `BJR-XXXX-XXXX-XXXX`; QR `{link}/r/{cod}`; "Página X de Y"; assinaturas "Presidência da assembleia" e "Secretaria
da assembleia"; arquivo `relatorio-consolidado-{id}.pdf`). Por evento junta todas as pautas, em ordem de abertura. Seções:
"Resumo da votação" ("{pauta}: {n} de {aptos} aptos votaram ({pc}%)." + "Resultado de {pauta}: {resultado}. Quórum:
atingido|não atingido|não exigido.") · "Identificação" (Condomínio, Evento, Votações neste relatório, Emitido em, Emitido por) ·
"Responsáveis designados" · por pauta "{n}. {pauta}" (Modalidade, Peso do voto, Período previsto, Hash da configuração; tabela
"Critérios": "{Mais votos} sobre {votos válidos}, quórum {50}%") · "{n}.1 Boletim de abertura" (Condução "Pela mesa, {1}ª
convocação", Aberta em, ...) · "{n}.2 Eleitorado e participação" (Eleitores aptos, Comparecimento, Faltosos, Inaptos na lista, Peso
total apto, Hash da lista; tabela "Participação por bloco ou torre": Bloco ou torre / Eleitores / Peso) · "{n}.3 Encerramento"
(Encerrada em, Cédulas, Motivo registrado) · "{n}.4 Apuração" (tabela por pauta, colunas Opção / Votos / Peso, linhas de cada opção,
"Abstenções", "Nulos", "Total", "Quórum: 52,30% (exigido 50%)" Atingido/Não atingido, "Resultado") ou "Votação ainda não apurada na
data de emissão deste relatório." · "{n}.5 Relação de votantes" ("Informa quem participou. Não indica em quem cada pessoa votou.")
· "Incidentes" ("Nenhum incidente registrado.") · "Termo de encerramento" ("Este relatório consolida os dados extraídos dos
registros da votação na data de emissão. ..." / "Os signatários declaram ter examinado o conteúdo deste relatório.").
Tipografia do PDF: Arial; título bold 23px `#0b3550`; títulos de seção bold 14.5px branco em faixa `#0b3550`; rótulos bold 13.5px
`#4a6373`, valores `#12242f`; tabela com cabeçalho `#d6e3ec`, cabeçalho bold 12.5px `#0b3550`, linhas 13.5px zebradas. O fundo de
segurança (guilhoché, selo, micro-texto) é desenho em canvas; num PDF de servidor, decidir o equivalente.
Sessão permanente aparece na ata parcial ("Ata parcial") só como participação, nunca resultado.

## 6. Fora do mínimo (deixar de fora agora; o desenho tem)

- Criptografia e urna: assinatura cega, cédula cifrada, cadeia de hash, código BJV de depósito, "Verificar urna", "Pacote público",
  "Verificador independente" (L185), boletins de abertura/apuração/urna (bobina 80 mm), hash do programa, registro em cartório.
- Guardiões (equipe com 5 guardiões, "Apuração com guardiões", "Abrir cédulas e totalizar"), etapa 6 "Equipe" (equipe própria,
  "Indicar pessoas"), perfis e "Atuando como" (L189), Perfis, Estatísticas, Auditoria (trilha), Testes, Gestor Master.
- Identidade forte: "Cadastro para votar" (aba `hab`, documento frente/verso, titularidade ou procuração, selfie, análise de
  qualidade de foto, PIN), selfie e documento no acesso por código, declaração do art. 299, consentimento LGPD de imagem,
  localização (geo), Validação/Eleitores, Avaliações do conselho e ocorrências, Conselho.
- Habilitação OAB/carteira profissional e os cenários que não são condomínio (podem continuar listados na etapa 1, decisão).
- Sessão permanente e alterar voto (art. 1.353): "Sessão permanente", "Retomar sessão", "Próxima sessão", "Ata parcial", limites 60/90 dias.
- Voto aberto/nominal (sigilo "Aberto"), "Lista nominal", "Documento de auditoria", "Relação de votantes" (opcional).
- Envio do comprovante por e-mail (precisa de e-mail transacional), "Abrir terminal presencial", "Simular votação" (opcional, é barato),
  campos de chapa da opção (foto, propostas, composição), "Conhecer candidaturas" (em pauta Sim/Não tem pouco uso), sons e braile
  (são da cabine; baratos, mas ficam a critério), cartaz PNG (barato).

## 7. CSS a copiar (seletores originais do desenho)

### 7.1 Base: gestão, assistente, portal, resultados, janelas
```css
body { margin:0; background:#f3f6f8; font-family:Arial, Helvetica, sans-serif; color:#12242f; }
.vC { background:#fff; border:1px solid #d3dde4; border-radius:3px; box-shadow:0 1px 3px rgba(18,36,47,.06); }
.vH { display:flex; align-items:center; gap:9px; flex-wrap:wrap; padding:10px 16px; background:#0a6fa0; color:#fff; font-size:15.5px; font-weight:bold; }
.vB { display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:36px; padding:0 14px; background:#fff; color:#0a6fa0; border:1px solid #0a6fa0; border-radius:3px; font:inherit; font-size:13.5px; font-weight:bold; cursor:pointer; }
.vB:hover { background:#3b8fc2; border-color:#3b8fc2; color:#fff; }
a.vB { text-decoration:none; }
.vP { background:#0a6fa0; color:#fff; }
.vB:disabled { opacity:.45; cursor:not-allowed; background:#fff; color:#5b7383; border-color:#c6d3dd; }
.vD { color:#a3232b; border-color:#a3232b; }
.vD:hover { background:#a3232b; border-color:#a3232b; }
.vL { display:flex; flex-direction:column; gap:4px; font-size:12px; font-weight:bold; color:#4a6373; min-width:0; }
.vF { height:36px; border:1px solid #d3dde4; border-radius:3px; padding:0 9px; font:inherit; font-size:14px; font-weight:normal; color:#12242f; background:#fff; box-sizing:border-box; width:100%; }
textarea.vF { height:66px; padding:7px 9px; resize:vertical; }
.vF:focus, .vB:focus-visible, .vTab:focus-visible, .uT:focus-visible { outline:3px solid #3b8fc2; outline-offset:1px; }
.vTab { padding:11px 15px; background:none; border:0; border-bottom:3px solid transparent; font:inherit; font-size:14px; font-weight:bold; color:#34505f; cursor:pointer; white-space:nowrap; display:inline-flex; gap:7px; align-items:center; }
.vTab:hover { color:#0a6fa0; }
.vTabOn { color:#0a6fa0; border-bottom-color:#0a6fa0; }
.vG2 { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:10px; }
.vKpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; }
.vKpi { padding:13px 15px; background:#fff; border:1px solid #d3dde4; border-left:4px solid #0a6fa0; border-radius:3px; }
.vKpi b { display:block; font-size:24px; color:#0b3550; }
.vKpi span { font-size:12.5px; color:#5b7383; }
.vChip { display:inline-block; padding:3px 9px; color:#fff; font-size:12px; font-weight:bold; border-radius:3px; white-space:nowrap; }
.vLin { padding:11px 15px; border-top:1px solid #e6edf2; }
.vLin:nth-child(even) { background:#f6f9fb; }
.vPas { font-size:12px; padding:3px 8px; background:#eef3f6; color:#5b7383; border-radius:3px; }
.vPasOk { background:#dcefe3; color:#1e7b45; }
.vPasAt { background:#0a6fa0; color:#fff; font-weight:bold; }
.vSec { grid-column:1 / -1; font-size:14px; font-weight:bold; color:#0a6fa0; border-bottom:1px solid #d3dde4; padding-bottom:4px; margin-top:6px; }
.vCats { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:8px; }
.vCat { display:flex; align-items:center; gap:9px; padding:13px 12px; background:#fff; border:1px solid #c9d8e2; border-radius:3px; font:inherit; font-size:13.5px; font-weight:bold; color:#0b3550; cursor:pointer; text-align:left; }
.vCat i { font-size:19px; color:#0a6fa0; }
.vCat:hover { background:#3b8fc2; color:#fff; }
.vCat:hover i { color:#fff; }
.vCatOn { background:#0a6fa0; color:#fff; border-color:#0a6fa0; }
.vCatOn i { color:#fff; }
.vAviso { padding:10px 14px; background:#fff8e6; border:1px solid #e6c56a; color:#6b4e00; font-size:13px; border-radius:3px; line-height:1.4; }
.vMono { font-family:"Courier New", Courier, monospace; word-break:break-all; }
.vMod { position:fixed; inset:0; z-index:4000; background:rgba(11,53,80,.55); display:flex; align-items:center; justify-content:center; padding:14px; }
.vModC { width:min(720px,100%); max-height:88vh; display:flex; flex-direction:column; background:#fff; border-radius:3px; overflow:hidden; }
@media (max-width:760px) { .uGr { grid-template-columns:1fr; } .uFen { display:none; } .uTela { min-height:300px; } .vG2 { grid-template-columns:1fr; } .vOc { display:none !important; } }
```

### 7.2 Cabine (inclui animações e movimento reduzido)
```css
.uBg { position:fixed; inset:0; z-index:2147483000; background:radial-gradient(circle at 50% 30%, #2d3640 0, #161b21 72%); display:flex; align-items:center; justify-content:center; padding:clamp(6px, 2vw, 22px); overflow:auto; animation:uEntra .45s ease both; }
@keyframes uEntra { from { opacity:0; } to { opacity:1; } }
body:has(.uBg) #bjtSelo, body:has(.uBg) #bjtMini, body:has(.uBg) #bjtFita { display:none !important; }
.uBody { position:relative; width:min(1060px, 100%); background:linear-gradient(180deg, #e3e7ec 0, #d5dae1 60%, #c6ccd4 100%); border-radius:14px 14px 10px 10px; padding:clamp(10px, 2vw, 22px) clamp(10px, 2vw, 24px) 10px; box-shadow:0 30px 80px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.8), inset 0 -3px 0 rgba(90,100,112,.25); box-sizing:border-box; animation:uSobe .5s cubic-bezier(.2,.8,.2,1) both; }
@keyframes uSobe { from { transform:translateY(26px) scale(.98); } to { transform:none; } }
.uTopo { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; color:#3a3f3f; font-size:12.5px; font-weight:bold; letter-spacing:.5px; }
.uGr { display:grid; grid-template-columns:minmax(0,1.62fr) minmax(260px,1fr); gap:clamp(12px, 3vw, 44px); align-items:stretch; }
.uMold { background:#cdd3da; padding:clamp(5px, .9vw, 10px); border-radius:3px; box-shadow:inset 0 1px 3px rgba(40,50,60,.55), 0 1px 0 rgba(255,255,255,.7); display:flex; }
.uTela { position:relative; flex:1; background:#fff; min-height:clamp(330px, 56vh, 470px); padding:clamp(14px, 2.4vw, 26px); box-sizing:border-box; display:flex; flex-direction:column; color:#111; font-family:Arial, Helvetica, sans-serif; overflow:hidden; box-shadow:inset 0 0 16px rgba(40,60,80,.14); }
.uDir { display:flex; flex-direction:column; gap:8px; }
.uPlaca { display:flex; align-items:center; gap:10px; padding:7px 14px; background:#eef1f4; border:1px solid #b9c0c9; box-shadow:0 1px 0 rgba(255,255,255,.8); }
.uPlaca img { height:clamp(38px, 5vw, 50px); mix-blend-mode:multiply; }
.uPlaca b { flex:1; text-align:right; font-size:clamp(16px, 2vw, 22px); line-height:1.05; color:#14181c; }
.uPlaca button { background:none; border:0; cursor:pointer; color:#3a4148; font-size:19px; padding:0; }
.uTec { flex:1; background:#15171a; border-radius:2px; padding:clamp(12px, 1.9vw, 22px) clamp(14px, 2.4vw, 30px); display:grid; grid-template-columns:repeat(3, 1fr); gap:clamp(7px, 1.2vw, 14px) clamp(14px, 2.4vw, 30px); align-content:center; box-shadow:inset 0 2px 8px rgba(0,0,0,.6); }
.uT { min-height:clamp(40px, 6.2vh, 54px); border:0; border-radius:3px; background:linear-gradient(180deg, #2b2e32, #0c0d0f); color:#f4f4f4; font:bold clamp(19px, 2.6vw, 24px) Arial; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:7px; box-shadow:0 3px 0 #000, 0 0 0 1px #33373c; transition:transform .06s, box-shadow .06s; }
.uT:active, .uPr { transform:translateY(3px); box-shadow:0 0 0 #000, 0 0 0 1px #33373c; }
.uB6 { font-size:.82em; color:#8b9198; font-weight:normal; }
.uAc { grid-column:span 3; display:grid; grid-template-columns:repeat(3,1fr); gap:clamp(8px, 1.2vw, 14px); margin-top:clamp(4px, 1vw, 10px); align-items:center; }
.uAc .uT { font-size:clamp(11px, 1.35vw, 13px); color:#16181a; min-height:clamp(42px, 6.4vh, 52px); box-shadow:0 2px 0 rgba(0,0,0,.65); }
.uBr { background:linear-gradient(180deg,#fff,#e2e2e2); }
.uCo { background:linear-gradient(180deg,#f0853a,#dd6a18); }
.uCf { background:linear-gradient(180deg,#25b65b,#149345); min-height:clamp(52px, 8vh, 64px) !important; }
.uFen { display:flex; justify-content:space-between; padding:clamp(8px, 1.2vw, 14px) 6% 2px; }
.uFen i { width:7px; height:18px; background:#6f7780; border-radius:1px; box-shadow:inset 0 2px 2px rgba(0,0,0,.5); transition:background .2s, box-shadow .2s; }
.uFen i.uLed { background:#3dff8f; box-shadow:0 0 12px 4px rgba(61,255,143,.85); transition:none; }
.uFaixa { margin-bottom:9px; padding:6px 12px; background:#8a6200; color:#fff; text-align:center; font-size:13px; font-weight:bold; border-radius:2px; letter-spacing:.3px; }
.uCard { display:flex; gap:12px; padding:10px 0; border-top:1px solid #d6dee5; }
.uNum { display:inline-flex; width:clamp(42px, 6vw, 56px); height:clamp(54px, 8vw, 72px); border:2px solid #111; align-items:center; justify-content:center; font:bold clamp(30px, 5vw, 44px) Arial; background:#fff; animation:uDig .16s ease; }
@keyframes uDig { from { transform:scale(.8); background:#dfe9ef; } to { transform:none; } }
.uCur { animation:uPisca 1s steps(2) infinite; background:#d7e6ef; }
@keyframes uPisca { 50% { background:#fff; } }
.uFade { animation:uSur .24s ease both; }
@keyframes uSur { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
.uFoto { align-self:flex-start; width:clamp(92px, 15vw, 146px); aspect-ratio:3/4; background:#dfe3e2; border:1px solid #666; display:flex; align-items:center; justify-content:center; font:bold 34px Arial; color:#4b5a63; overflow:hidden; flex:none; }
.uFoto img { width:100%; height:100%; object-fit:cover; }
.uRod { margin-top:auto; border-top:2px solid #111; padding-top:8px; font-size:clamp(12.5px, 1.6vw, 15px); line-height:1.5; }
.uFim { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; background:#fff; padding:16px; box-sizing:border-box; animation:uSur .3s ease both; }
.uAnel { width:clamp(112px, 18vw, 156px); height:clamp(112px, 18vw, 156px); }
.uAnel circle { fill:none; stroke:#1e7b45; stroke-width:7; stroke-dasharray:302; stroke-dashoffset:302; transform:rotate(-90deg); transform-origin:50% 50%; animation:uRing .9s ease-out forwards; }
.uAnel path { fill:none; stroke:#1e7b45; stroke-width:9; stroke-linecap:round; stroke-linejoin:round; stroke-dasharray:90; stroke-dashoffset:90; animation:uRing .4s .75s ease-out forwards; }
@keyframes uRing { to { stroke-dashoffset:0; } }
.uProg { width:64%; height:9px; background:#d5d2c6; border-radius:2px; overflow:hidden; }
.uProg div { height:100%; background:#1e7b45; animation:uBarra 1.1s ease-in-out infinite; width:40%; }
@keyframes uBarra { from { margin-left:-40%; } to { margin-left:100%; } }
.uDemo { margin-top:9px; text-align:center; font-size:11.5px; color:#51575a; }
.uSr { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); }
@media (prefers-reduced-motion: reduce) { .uBg, .uBody, .uNum, .uFade, .uFim, .uCur { animation:none !important; } .uAnel circle, .uAnel path { animation-duration:.01s !important; animation-delay:0s !important; } }
```
Regras do desenho que ficam de fora no mínimo: `.vB.vKey:disabled` (guardiões), `.vAv`/`.vAv img` (foto de candidatura; entra se
"Conhecer candidaturas" ou foto da opção entrarem), `.vFoto*`, `.vQ` (fotos de identidade), `.vPerG*` (perfis), `.vPortal ...`
(esconde widgets do protótipo), `.vPasso`.
