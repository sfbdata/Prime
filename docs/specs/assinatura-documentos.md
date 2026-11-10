# Assinatura de documentos (tela 17)

Fase 1: análise e spec, sem código. Pedido do Coordenador em 09/11/2026.

- **Desenho**: `docs/design/redesign-08-11-26/17 - Assinatura - VS 08.11.26.dc.html` (arquivo inteiro, 475 linhas),
  com `bj-assina-modelos.js`, `bj-assina-pdf.js`, `bj-central-docs.js`, `support.js` e `bootstrap-icons` na mesma
  pasta. Original: https://claude.ai/code/artifact/3c9fe618-65e4-457f-8d3d-c31bb68901c8. A pasta do desenho fica na
  cópia principal do repositório e não entra neste PR (o `bj-assina-modelos.js` tem 620 KB).
- **Regras do Farlei para o sistema real**: `ESPEC_ASSINATURA.md`, na mesma pasta. Onde esta spec cita "ESPEC", é ela.
- **Risco**: MÉDIO (módulo e permissão novos, rota pública sem login, evidência que vira prova). O PR da tela pública
  (etapa 2) e o da validação de PDF assinado (etapa 5) passam por revisão independente antes da vigia.
- **Nome no código**: domínio `App\AssinaturaEletronica` (ver seção 5). Na tela, o nome continua "Assinatura".

## 1. O que o desenho mostra, por etapa

O protótipo guarda tudo no navegador (`localStorage` e IndexedDB). Nesta seção, **[servidor]** marca o que o
protótipo faz no navegador e o sistema real faz no servidor; **[sem lastro]** marca o que o protótipo mostra mas não
produz de verdade.

### 1.1 Lista (aba Documentos)

- Título "Assinatura" e botão primário "Novo documento". Cartão com 3 abas: **Documentos**, **Modelos**, **Validar**.
- Filtros em chips com contador: Todos, Aguardando, Concluídos, Rascunhos, Recusados, Expirados, Lixeira. Não há chip
  "Cancelados": cancelado aparece em Todos. Combinam com a busca ("Buscar por documento ou signatário"), o seletor
  "Todas as pastas" e o filtro de cor.
- Tabela: caixa de seleção; Documento (nome, "categoria · pasta", vínculo "Na pasta 828 · Cliente" ou "Irá para a pasta
  828 · Cliente"); Signatários ("1 de 2 assinaram" com barra verde); Criado em; Prazo (só em Aguardando); Status (selo);
  ações Copiar link, Enviar lembrete, Vincular à pasta de Expediente, Baixar PDF com selo, Abrir, Mais opções.
- Situações: Rascunho `#5b7383`, Aguardando `#0a6fa0`, Concluído `#1e7b45`, Recusado `#a3232b`, Expirado `#8a6200`
  (calculado: Aguardando com prazo vencido), Cancelado `#7a8e9b`.
- Cor da linha (8 opções), seleção em lote (cor, Excluir, Restaurar, Limpar seleção), menu de contexto no botão direito
  (Abrir, Continuar e enviar, Copiar link, Enviar lembrete, Baixar PDF com selo, Vincular à pasta, Duplicar, Selecionar,
  Selecionar tudo, Restaurar, Excluir, Excluir definitivamente, Cor da linha).
- **Lixeira**: Excluir leva à Lixeira, cancela o envio em andamento e desativa os links; o documento e as evidências
  ficam. Restaurar não reativa o envio. Só rascunho pode ser apagado de vez. Duplicar cria rascunho "(cópia)" com links
  novos.
- Vazio: "Nenhum documento neste filtro." Em telas de até 760px, a tabela vira cartões.

### 1.2 Novo documento (assistente em 4 passos: Documento, Dados, Signatários, Enviar)

- **Passo 1, Documento**: enviar arquivo avulso (PDF ou .docx) ou escolher um dos **13 modelos prontos** (5 procurações,
  substabelecimento, renúncia, contrato de honorários, notificação de cobrança, 4 declarações), agrupados por categoria,
  com busca e "Criar novo modelo". Arquivo enviado pula o passo Dados. PDF vira imagem por página para a prévia; .docx
  entra só como texto.
- **Passo 2, Dados** (só modelo): logo do documento (BlueJus, Meu Escritório, ou enviar uma, com "Usar esta logo nos
  próximos documentos"), nome, pasta (texto livre com sugestões), "Folha branca (sem marcas, selo e código)", cláusulas
  por caixas de marcar (o contrato tem o grupo obrigatório "Forma de remuneração" e 8 cláusulas opcionais), grade de
  campos e prévia A4 ao vivo com campo vazio em amarelo. Dados do escritório e do advogado vêm sozinhos.
- **Passo 3, Signatários**: "Quem precisa assinar", "Assinar na ordem da lista", "Incluir-me", "Adicionar"; por
  signatário: Nome, E-mail, CPF (opcional, com dígito conferido), Papel e **Forma de assinatura** (o nível). O modelo já
  traz os signatários (ex.: Contratante e Contratado(a)).
- **Passo 4, Enviar**: mensagem aos signatários, prazo em dias (1 a 90, padrão 7), resumo, prévia, "Salvar rascunho" e
  "Gerar links e enviar".

### 1.3 Níveis de assinatura

| Nível no desenho | O que o signatário faz | Classe que o manifesto imprime |
|---|---|---|
| Simples: e-mail e código | nome, CPF, assinatura desenhada ou digitada, código de 6 dígitos, aceite | Eletrônica simples |
| Reforçada: código, selfie e localização | o mesmo, mais selfie com documento e localização do navegador | Eletrônica com múltiplos fatores |
| Conta gov.br | baixa o PDF, assina em assinador.iti.br, devolve o PDF assinado | Avançada (gov.br) |
| Certificado ICP-Brasil | baixa o PDF, assina com A1 ou A3, devolve | Qualificada (ICP-Brasil) |

### 1.4 Envio e acompanhamento (detalhe do documento)

- Link único por signatário (`/assinar/<token>`). Por signatário pendente: Copiar link, E-mail, WhatsApp (`wa.me` sem
  número), abrir a tela do signatário.
- Detalhe: cartão Signatários (situação, papel, nível, data e método), cartão do código de validação (Continuar e
  enviar, Baixar PDF com selo, Vincular à pasta, Cancelar envio), cartão **Trilha de auditoria** e prévia A4.
- **[sem lastro]** No protótipo nenhum e-mail sai, o lembrete só grava um evento e o código aparece na tela. A ESPEC manda
  enviar por e-mail: no sistema, "Gerar links e enviar" manda o e-mail a cada signatário com e-mail.

### 1.5 Tela pública do signatário (sem conta)

- Cabeçalho próprio azul `#0a76a8` com a logo e o nome do documento; prévia à esquerda e painel "Assinar documento" à
  direita (abaixo da prévia em telas de até 980px).
- Bloqueios: "Assinatura já registrada", "Assinatura recusada", "Envio cancelado", "Prazo encerrado", "Aguardando
  assinatura anterior"; link desconhecido: "Link inválido."
- Formulário: saudação com a mensagem, nome completo, CPF, abas Desenhar ou Digitar, código enviado ao e-mail (Enviar
  código e Reenviar), selfie e localização (Reforçada), passos de baixar, assinar fora e devolver (gov.br e ICP), aceite
  "Li o documento e concordo em assiná-lo eletronicamente...", Assinar, Recusar assinatura (com motivo).
- Ao assinar: som de dois tons, vibração e tela cheia verde "Assinatura registrada", com o código e "Baixar PDF com selo".
  Quando todos assinam: Concluído e, se houver vínculo, o PDF vai para a pasta.

### 1.6 PDF final, selo e validação

- Folha A4 com fundo de segurança (degradê, linhas, rosácea, marca d'água, moldura, microtexto, selo holográfico),
  logo, lugares de assinatura com a rubrica e "Assinado eletronicamente em ...", rodapé em toda página com QR, código
  `BJA-XXXX-XXXX-XXXX`, SHA-256, "Emitido em ... Vale somente com registro correspondente no BlueJus." e última página
  **Manifesto de assinaturas** (por signatário: nome, papel, CPF mascarado, e-mail mascarado, classe da lei, método, data
  ou recusa, trilha de eventos). Folha branca: sem fundo, sem rodapé e sem selo, mas o manifesto mantém código e trilha.
- Formato "folha única em duas colunas" (contrato), com texto lateral e fonte que diminui para caber.
- Aba **Validar**: código digitado, QR pela câmera ou por foto; mostra nome do documento, situação, hash e cada
  signatário com a data. ESPEC: rota pública `/assinar/validar/{codigo}`.

### 1.7 Documento já assinado (PDF com assinaturas digitais)

- Ao enviar um PDF que já tem assinatura digital, o desenho reconhece cada uma e confere faixa de bytes, resumo e chave
  do certificado (RSA, PSS, ECDSA), lê DocMDP e guarda o original byte a byte. Novas assinaturas gov.br ou ICP precisam
  **começar exatamente com a versão anterior**. Há cópia opcional com carimbo visível (acréscimo incremental) e o link
  "Conferir no Validar (ITI)". O desenho não confere cadeia, revogação nem carimbo do tempo.

### 1.8 Modelos

- Aba Modelos: modelos prontos ("Duplicar e adaptar") e modelos do escritório ("Editar", "Excluir").
- Editor: nome, categoria, formato (página inteira ou folha única em duas colunas), folha branca, texto lateral, barra
  de formatação, "Inserir campo..." (Cliente, Escritório, Local e data), "Criar campo", lugar de assinatura por papel,
  "Importar Word" e prévia ao vivo. O documento enviado guarda cópia do conteúdo: editar ou excluir o modelo não muda o
  enviado.

### 1.9 Vínculo com a pasta de Expediente

- Modal "Vincular à pasta de Expediente" com busca por NUP, cliente ou ação. Concluído: o PDF com selo vai para os
  documentos da pasta e o histórico registra. Aguardando: vai sozinho quando todos assinarem.

### 1.10 O que o desenho não mostra e esta spec decide pela convenção

| Omissão | Decisão |
|---|---|
| Permissões | módulo `assinatura_eletronica` (ver seção 5.4); quem tem o módulo vê os documentos do escritório |
| Documento recusado e os outros signatários | o documento fica Recusado e os links dos demais deixam de valer (segue a situação que o próprio desenho grava) |
| Reenviar e prorrogar prazo | não entram: o desenho resolve pelo Duplicar |
| Telefone do signatário | não entra: o desenho manda `wa.me` sem número |
| Expirado | calculado na consulta (Aguardando com prazo no passado), como o desenho |
| Cor da linha e logo padrão | gravadas por documento e por escritório, como o protótipo (que isola por conta) |
| Travessões do desenho (3, todos marcadores de vazio) | célula vazia na lista; "não informado" no manifesto |
| Valores fixos do escritório do Farlei e a logo PRIME no código | vêm de Meu Escritório (`Tenant`: nome, CNPJ, endereço, OAB, telefone, e-mail, logo) e do perfil do usuário; campo sem dado sai como linha em branco, como o desenho já faz |
| Cabeçalho e menu superior do redesign (EXPEDIENTE, DEMANDAS, PONTO, ASSINATURA, VOTO) | moldura do sistema (`base.html.twig`), como em `docs/specs/agenda-1a.md`: a tela é o conteúdo e o link entra no menu existente (decisão D4, seção 7). A tela pública do signatário não usa a moldura: fica com o cabeçalho próprio do desenho, porque é página sem login |

## 2. O que o sistema já tem e será reusado

| Precisa de | Já existe | Uso |
|---|---|---|
| Arquivo por chave, SHA-256, remoção depois do COMMIT | `Shared/Armazenamento`, `Sha256DeArquivo`, `TransacaoComArquivoNovo`, `RemocaoAposTransacao` | categoria nova `ASSINATURA_DOCUMENTO` (resolvedor local, layout do R2, testes, fontes de referência) |
| PDF a partir de HTML | `GeradorDePdfComDompdf`, `ExportarPecaTextoUseCase::gerarPdf` (sem acesso remoto, chroot em `AreaTemporariaPrivada`) | prévia, PDF para assinar e PDF final |
| QR no servidor | `bacon/bacon-qr-code` (`TrabalhoExterno/Service/QrCodeEmSvg`); no navegador, `public/js/vendor/qrcode-generator` | rodapé do PDF e validação |
| Texto de .docx | `ChatIa/Service/ExtratorDeTextoDocx` | upload de .docx (o desenho só usa o texto); entra por contrato ou vai para `Shared` |
| E-mail e cota | mailer, `IdentidadeDeEmail`, `EnvioDeEmailAoClientePeloMailer`, `CotaDeEnvio` (24 h corridas) | convite para assinar, código, lembrete, aviso ao próximo, aviso de recusa e de conclusão |
| Código de uso único por e-mail | `SolicitarCodigoSenhaUseCase` / `ConfirmarCodigoSenhaUseCase` (HMAC, 15 min, 5 tentativas, teto diário, `hash_equals`) | código do signatário |
| Link público com token | `LinkExternoDoPush` + `PushCompartilhadoController` + `PushCompartilhadoSemTenantFilterListener` (token aleatório, prazo, tentativas, bloqueio, CSRF, limitador, `noindex`, `no-store`) | `/assinar/{token}`: guardar só o hash do token, como o convite (`Invitation::hashDoToken`) |
| Página pública de validação | `VerificacaoDoCalculoController` + `CalculoEmitido` (código único, hash do PDF, comparação em tempo constante) | `/assinar/validar/{codigo}` |
| Lixeira | `Descartavel`, `LixeiraFilter`, `AcessoALixeira` | Lixeira dos documentos, sem purga automática (ver seção 3.6) |
| Documento na pasta e "contrato assinado" | `PastaDocumento` (selo `assinado_em`, `OrigemDaAssinatura`), `MarcarContratoAssinadoPeloArquivoUseCase` (único chamador hoje: upload da caixa CONTRATOS do Financeiro) | ao concluir com vínculo: grava `PastaDocumento`, marca assinado e, se for contrato, chama o UseCase |
| Reconhecer PDF assinado | `Pasta/Service/DetectorDeAssinaturaDigital` (só reconhece, não valida) | ponto de partida da etapa 5 |
| Dados do signatário | `ClientePF` (nome, CPF, e-mail), `ClientePJ` (representante), `ClienteEmail`, `PastaReu` | pré-preencher o signatário; o signatário guarda cópia, nunca referência viva |
| CPF | `Shared/Service/DigitoVerificador::cpfValido` | CPF do cadastro e do formulário público |
| Localização | padrão do ponto (`ponto-batida.js`, `RegistroPonto` com latitude e precisão) | nível Reforçada |
| Módulo novo | receita do Cálculo (migration de permissão idempotente, fixture, menu, `canAccessModule` em toda rota) e chave do Master (`CatalogoDeRecursos`, modelo `pushpub` para página pública) | módulo e recurso `assinatura` |

**Não existe e entra no projeto**: trilha encadeada (o `audit_log` não é encadeado e não serve como prova), conferência
de assinatura de PDF, juntar ou carimbar PDF existente (não há FPDI nem TCPDF), captura de selfie, envio real por
WhatsApp, captcha.

**Achado que afeta esta tela e o sistema hoje**: `symfony/process` está só em `packages-dev` do `composer.lock`
(v7.4.21), e a imagem de produção instala com `--no-dev`. A `ExecucaoDoGhostscript` usa essa classe, então o Ghostscript
provavelmente não roda em produção hoje (a compressão é de melhor esforço e cai no log). As etapas 3 e 5 dependem do
Ghostscript: mover `symfony/process` para `require` é pré-requisito e sai num PR separado, `fix-symfony-process-producao`.

**Câmera bloqueada em produção**: `nginx/conf.d/nginx.prod.conf` (linhas 51 e 233) manda
`Permissions-Policy "... camera=()"`. A selfie (etapa 7) e a leitura de QR pela câmera na aba Validar (etapa 3) só
funcionam com `camera=(self)`. Mudar o nginx e publicar é do Samuel.

## 3. Fornecedor, autorização e lei

Fontes e preços completos ficam no relatório de pesquisa de 09/11/2026 (60 fontes). Preço de fornecedor muda rápido:
tratar como ordem de grandeza e cotar antes de contratar.

### 3.1 Validade jurídica (Lei 14.063/2020 e MP 2.200-2/2001)

- A Lei 14.063 classifica as assinaturas (simples, avançada, qualificada), mas **não se aplica a processos judiciais nem
  a interações entre particulares** (art. 2º, parágrafo único). Escritório e cliente são particulares: a validade vem da
  MP 2.200-2, art. 10, §2º (meio aceito pelas partes). A classificação serve de régua no manifesto.
- Onde cada nível cai: Simples = simples; Reforçada = simples com múltiplos fatores (chamar de "avançada" é tese, ninguém
  certifica); gov.br = avançada; ICP-Brasil = qualificada. O desenho já imprime exatamente essas classes.
- Procuração: STJ, 3ª Turma, aceitou procuração sem ICP-Brasil (REsp 2.223.695/SP, março de 2026), mas o juiz pode
  exigir assinatura qualificada se houver dúvida (notícia do STJ de 31/03/2026). A Corregedoria do TJSP (parecer de
  20/01/2022) exige qualificada para procuração em processo eletrônico. Contrato de honorários: vale a regra geral entre
  particulares.
- **Decisão**: manter as classes do desenho, sem aviso extra na procuração (D5 da seção 7). O nível mínimo por tipo de
  documento fica para o pacote jurídico (seção 9).
- Evidências que os tribunais olharam (entram na etapa 2): hash do PDF enviado e do final; quem pediu e quem assinou;
  canal, destino e hora do envio; hash do código (nunca o código), envio, conferência e tentativas; IP com porta,
  navegador e hora do servidor; aceite com o texto exibido, versionado; imagem da rubrica; localização só com permissão;
  trilha só de acréscimo, encadeada pelo hash do evento anterior.

### 3.2 gov.br

- A API de assinatura gov.br é só para órgão público, pedida por agente público. O ITI anunciou em 06/2025 que abriria a
  empresas privadas, mas até 09/11/2026 não há regra publicada. **Não depende de nós**.
- **Recomendação**: o fluxo manual do desenho (baixar, assinar em assinador.iti.br, devolver), com conferência no
  servidor de integridade, CPF do certificado igual ao do signatário e conteúdo igual ao enviado. A cadeia gov.br ("AC
  Raiz do gov.br") **não é ICP-Brasil** e precisa de âncora própria. Falta testar se o assinador.iti.br preserva os bytes
  do PDF (acréscimo incremental): se não preservar, a conferência de prefixo do desenho não se aplica ao gov.br e o
  conteúdo é conferido pelo código impresso no rodapé e pelo número de páginas. Teste manual na etapa 5.
- Validar do ITI: gratuito, sem API pública. Fica como link no detalhe, como o desenho mostra.

### 3.3 ICP-Brasil e validação da cadeia

- Nenhuma biblioteca PHP madura confere cadeia, revogação (LCR/OCSP) e carimbo do tempo. Opções:
  1. **pyHanko** (Python, código aberto) num container interno chamado pelo PHP, com as cadeias ICP-Brasil e gov.br
     baixadas por tarefa agendada. Grátis; um serviço a mais para manter.
  2. **Lacuna Rest PKI** (nuvem, cliente PHP `lacuna/restpki-client`): cerca de R$ 0,20 por validação (contrato público
     de 2023). Pago, sem servidor novo; o Web PKI (A3 no navegador) vem sem licença à parte nesse caso.
  3. Só integridade em PHP (o que o desenho faz) e o link para o Validar do ITI. Não cumpre a ESPEC ("conferir a
     assinatura e a cadeia no servidor").
- **Recomendação**: integridade em PHP já na etapa 5 (porta do `bj-assina-pdf.js` com `openssl`), e a cadeia pela opção 1
  na etapa 6. Decidido: pyHanko (D2, seção 7). A3 no navegador fica fora: o cliente assina no programa dele e devolve o PDF.

### 3.4 Biometria (nível Reforçada)

- Comparar a selfie com o documento ou com base oficial exige fornecedor: Unico (via Didit, cerca de US$ 0,20 a 0,35 por
  verificação, 09/2026), Didit (face match US$ 0,05 e prova de vida US$ 0,10), Serpro Datavalid (base oficial, preço por
  cotação). Não construir comparação de face.
- **Recomendação**: na etapa 7, guardar selfie com documento, localização e o código, sem comparação de face, e imprimir
  "Eletrônica com múltiplos fatores" (como o desenho). Comparação de face só depois, com fornecedor contratado.
  Decidido assim (D1, seção 7).

### 3.5 Selo do PDF final

- ESPEC: "PDF gerado em imagem no servidor, com HMAC e rota pública /assinar/validar/{codigo}". Etapa 3 cumpre isso com um
  segredo próprio do selo (variável nova, criada pelo Samuel; não reaproveitar o `APP_SECRET`, que gira).
- **Recomendação de reforço**: selar o PDF final em PAdES com um e-CNPJ A1 do BlueJus (R$ 165 a 280 por ano) e carimbo do
  tempo de ACT ICP-Brasil. Assim qualquer alteração fica detectável por qualquer validador. Exige comprar o certificado:
  a compra fica com o Samuel, depois do piloto (D3, seção 7). Etapa 8, opcional; não trava as anteriores.

### 3.6 LGPD

- Papéis: o escritório é o controlador; o BlueJus, operador; fornecedor de e-mail e de biometria, suboperadores.
- Selfie e foto de documento são dados pessoais; viram dado sensível (biometria, art. 5º, II) quando usados para
  identificar pelo rosto. Base para a biometria: art. 11, II, "g" (prevenção à fraude), escolhida antes de ligar o
  recurso, com caminho sem biometria sempre disponível.
- Menor (art. 14): na procuração de menor quem assina é o responsável; o desenho também prevê "Menor assistido(a)"
  assinar quando o ato é assistência. Não colher selfie de menor.
- Retenção: o desenho não tem prazo para a Lixeira e diz que evidências ficam preservadas. **Recomendação**: sem purga
  automática dos documentos enviados; selfie e foto do documento com prazo próprio. Prazo exato (sugestão: até 5 anos
  depois do fim da relação, EOAB art. 25) vai para o pacote jurídico, sem travar a implementação.
- A página pública de validação mostra o que o desenho mostra (nome do documento, situação, hash, nome e data de cada
  signatário), nunca CPF nem e-mail.

### 3.7 WhatsApp

- `wa.me` com o link, como o desenho. O código de uso único nunca passa pelo WhatsApp do escritório (o advogado veria o
  código e cairia o "controle exclusivo" do cliente). API oficial (cerca de US$ 0,0068 por mensagem de utilidade) só se
  houver número central do BlueJus: fora desta frente.

### 3.8 Comprar em vez de construir

- ZapSign, Clicksign e Autentique entregam quase tudo por API (de R$ 0,07 a alguns reais por documento), mas o cliente
  assina na tela deles, salvo plano white-label alto: a tela deixa de ser a do desenho.
- **Recomendação**: construir os níveis Simples, gov.br e ICP no BlueJus (é o que o desenho mostra) e comprar só a
  comparação de face, se for querida.

## 4. Etapas (PRs pequenos)

Cada etapa sai num PR, com teste antes e arquivo em `mudancas/`. A tela fica atrás da chave `assinatura` do Master
(desligada) até a etapa 3, para nenhum escritório ver documento sem PDF final.

| Etapa | Entrega | Tamanho | Migration | Arquivos quentes |
|---|---|---|---|---|
| 1 | Domínio, módulo e lista com rascunho | G | M1 | sidebar, `PurgarEscritorioUseCase`, Master |
| 2 | Envio e assinatura Simples (tela pública) | G | (na M1) | `security.yaml`, `framework.yaml`, listeners públicos |
| 3 | PDF com selo, manifesto, Validar e vínculo à pasta | G | M2 | variável do segredo (o `symfony/process` sai antes, no PR `fix-symfony-process-producao`) |
| 4 | Modelos do escritório (editor) | M | M3 | nenhum |
| 5 | gov.br, ICP e documento já assinado (integridade em PHP) | G | M4 | nenhum |
| 6 | Cadeia ICP-Brasil e gov.br no servidor | M | (na M4) | `docker-compose`, container pyHanko novo |
| 7 | Reforçada: selfie com documento e localização | M | M5 | nginx de produção (câmera) |
| 8 | Selo PAdES com e-CNPJ e carimbo do tempo (opcional) | M | nenhuma | segredo do certificado |

### Etapa 1: domínio, módulo e lista com rascunho

- Entidades `DocumentoParaAssinar` (TenantAware, Descartavel, Auditavel), `Signatario`, `EventoDaTrilha`
  (só acréscimo, `hash_anterior` + `hash`) e o catálogo dos 13 modelos prontos em código (conteúdo do
  `bj-assina-modelos.js`, sem as logos, com os dados de cliente já trocados por campos).
- `MontadorDoDocumento`: aplica `[[campo]]`, `[[^campo]]`, `{b}`, numeração (`Cláusula @:`, `@ - `, `1.@)`), condições
  `se` e cláusulas `op`, e gera os blocos congelados que o documento guarda.
- Tela da lista (aba Documentos inteira: filtros, contadores, busca, pasta, cores, seleção, menu de contexto, Lixeira,
  Duplicar, Excluir definitivamente só rascunho) e assistente passos 1 a 3 (modelos prontos, upload de PDF e .docx com
  prévia, campos, cláusulas, logo, signatários), "Salvar rascunho". Abas Modelos e Validar aparecem; o conteúdo delas
  entra nas etapas 3 e 4 (até lá a chave do Master segura a tela).
- Módulo `assinatura_eletronica` (permissão, fixture, menu lateral, `canAccessModule` em toda rota), recurso `assinatura`
  no `CatalogoDeRecursos`, tabelas novas no `PurgarEscritorioUseCase`.
- Testes: UseCases (criar, salvar rascunho, duplicar, excluir, restaurar), `MontadorDoDocumento` com cada sintaxe, lista
  por escritório (isolamento), arranjo da tela por filho direto e textos do desenho.

### Etapa 2: envio e assinatura Simples

- Passo 4 e "Gerar links e enviar": congela o conteúdo, gera o PDF para assinar e o hash, cria o token de cada
  signatário (só o hash no banco, prazo = prazo do documento), manda o e-mail a quem tem e-mail.
- Tela pública `/assinar/{token}` com layout próprio: bloqueios, ordem, código de 6 dígitos por e-mail (HMAC, 15 min,
  5 tentativas, teto diário), desenhar ou digitar, aceite com texto versionado, recusa com motivo, tela "Assinatura
  registrada". Limitador por IP e por token, CSRF por token, `noindex` e `no-store`, filtro de escritório desligado por
  prefixo de rota e o escritório lido do próprio documento.
- Evidências: IP e porta, navegador, hora do servidor, envio e conferência do código, abertura do link, aceite, tudo na
  trilha encadeada.
- Detalhe do documento: signatários, Copiar link, E-mail (do servidor), WhatsApp (`wa.me`), lembrete por e-mail ao
  primeiro pendente, Cancelar envio (com confirmação no modal do desenho, o mesmo do Excluir: D5), trilha. Avisos por
  e-mail: próximo da fila, recusa e conclusão ao remetente.
- Revisão independente antes da vigia (rota pública).

### Etapa 3: PDF com selo, manifesto, Validar e vínculo à pasta

- PDF final no servidor, em imagem como a ESPEC pede: a GD compõe cada página inteira com o fundo de segurança (mesmas
  funções do protótipo, semente pelo hash) e um escritor próprio monta uma JPEG por página, como o desenho (troca do
  Dompdf + `pdfimage24` registrada na E3-1 de `assinatura-documentos-etapa-3.md`);
  rodapé com QR, código, SHA-256 e "Emitido em"; Manifesto de assinaturas; formato em duas colunas; folha branca.
  Pré-requisito: `symfony/process` em `require` (PR `fix-symfony-process-producao`).
- Código `BJA-XXXX-XXXX-XXXX` derivado por HMAC com o segredo do selo, único no banco.
- Rota pública `/assinar/validar/{codigo}` e aba Validar (código, câmera e foto do QR com `jsQR` servido do próprio
  sistema, não de CDN). A câmera depende do nginx (seção 2).
- Vincular à pasta de Expediente: grava `PastaDocumento` (categoria pelo tipo do modelo), marca assinado e, se for
  contrato, chama `MarcarContratoAssinadoPeloArquivoUseCase`. Vincular exige editar a pasta; o rótulo da pasta na lista
  só aparece para quem pode ver a pasta.
- Ao fim desta etapa a chave do Master pode ser ligada para o escritório piloto.

### Etapa 4: modelos do escritório

- Aba Modelos (Duplicar e adaptar, Editar, Excluir com confirmação no modal do desenho: D5), editor com barra, campos,
  assinaturas, Importar Word, prévia.
- Nenhum HTML chega ao servidor: o navegador converte o editor em `corpo` (o `lerEd` do desenho) e reabre pelo
  `htmlDe`; o servidor recebe só a estrutura (nome, categoria, formato, lateral, folha branca, campos, signatários e
  corpo) e a confere com um validador estrito, que recusa com mensagem na tela em vez de cortar. Motivo: nada de HTML
  no banco, e o `SanitizadorDeModeloDePeca` tira os `data-*` de que o editor precisa e corta em silêncio acima de
  256 KB. Contrato: `docs/specs/assinatura-documentos-etapa-4.md`.

### Etapa 5: gov.br, ICP e documento já assinado

- Passos "Baixar PDF para assinar", link externo, "Enviar PDF assinado".
- Porta do `bj-assina-pdf.js` para PHP: faixas de bytes, resumo, chave do certificado (`openssl`), DocMDP, prefixo byte a
  byte com a versão anterior, CPF do certificado igual ao do signatário.
- Documento já assinado: original guardado byte a byte, cada versão devolvida guardada, quadro "Documento já assinado",
  cópia com carimbo incremental opcional (acréscimo de bytes, como o protótipo faz), "Baixar original com as assinaturas".
- Teste manual com o assinador.iti.br (preserva ou não os bytes) antes de fechar a conferência do gov.br.
- Revisão independente antes da vigia.

### Etapa 6: cadeia no servidor

- Container interno com pyHanko, chamado pelo PHP (D2). Resultado gravado: situação, cadeia, CPF ou CNPJ,
  data, carimbo do tempo. Cadeias ICP-Brasil e gov.br atualizadas por tarefa agendada.

### Etapa 7: Reforçada

- Selfie com documento (câmera frontal) e localização, arquivos cifrados na categoria do documento, acesso restrito,
  prazo próprio. Sem comparação de face nesta versão (D1); ela só entra depois, com fornecedor contratado.

### Etapa 8: selo PAdES (opcional)

- PDF final assinado com o e-CNPJ A1 do BlueJus e carimbo do tempo. Só se o Samuel decidir comprar o certificado,
  depois do piloto (D3).

## 5. Modelo de dados e nomes

### 5.1 Nome do domínio

"Assinatura" já é usada no Contribua (tabela `contribua_assinatura`, rotas `contribua_assinatura_*`, várias classes) e no
selo "Assinado" dos documentos da pasta. Opções: `AssinaturaEletronica` (termo da lei, nenhum uso no sistema; nome
longo), `Firma` (curto, sem colisão; em português também quer dizer empresa), `Subscricao` (sem colisão como palavra; o
grep mistura com `EventSubscriber`). **Escolha: `AssinaturaEletronica`**: pasta `app/src/AssinaturaEletronica/`, tabelas
`assinatura_eletronica_*`, rotas `assinatura_eletronica_*`, módulo `assinatura_eletronica`. Endereços: tela interna
`/assinatura` (livre: a do Contribua é `/contribua/assinatura`) e públicas `/assinar/{token}` e `/assinar/validar/{codigo}`
(as do desenho).

### 5.2 Tabelas (M1, salvo indicação)

| Tabela | Principais colunas |
|---|---|
| `assinatura_eletronica_documento` | tenant, criado_por, nome, pasta_rotulo, modelo_chave, categoria, blocos (JSON congelado), logo_chave, folha_branca, layout, em_ordem, mensagem, situacao, criado_em, enviado_em, vence_em, concluido_em, codigo (único, M2), hash_enviado, hash_final, arquivo_enviado_chave, arquivo_final_chave (M2), cor, excluido_em, excluido_por, pasta_id e vinculo_pendente (M2) |
| `assinatura_eletronica_signatario` | documento, ordem, nome, email, cpf, papel, nivel, situacao, token_hash (único), codigo_hash, codigo_enviado_em, tentativas, bloqueado_ate, assinado_em, nome_digitado, cpf_digitado, rubrica_chave, metodo, motivo_recusa, ip, user_agent, latitude, longitude, precisao (M5: selfie_chave) |
| `assinatura_eletronica_evento` | documento, signatario (opcional), usuario (opcional), tipo, dados (JSON), ip, user_agent, criado_em (servidor), hash_anterior, hash; sem UPDATE nem DELETE |
| `assinatura_eletronica_modelo` (M3) | tenant, nome, categoria, campos (JSON), signatarios (JSON), corpo (JSON), layout, lateral, folha_branca, criado_por, criado_em, alterado_em (sem icone, descricao nem html: etapa 4) |
| `assinatura_eletronica_versao` (M4) | documento, signatario (opcional), ordem, arquivo_chave, sha256, tamanho, analise (JSON), criado_em |
| logo padrão do escritório | coluna no `tenant` ou na configuração do módulo (decidir na etapa 1 com a skill `criar-entity`) |

### 5.3 Arquivos

Categoria nova `ASSINATURA_DOCUMENTO` (com isolamento físico por escritório): PDF enviado, rubricas, PDF final, versões
devolvidas e, na etapa 7, selfies. Chaves pela fábrica `ChavesDeAssinaturaEletronica`, remoção depois do COMMIT, tudo
pela skill `armazenamento-de-arquivos`.

### 5.4 Permissões

- `modules.assinatura_eletronica.view`: ver a tela e os documentos do escritório, criar, enviar, cancelar, mover para a
  Lixeira, editar modelos. Concessão inicial aos perfis que já têm `modules.pastas.view` (receita do Cálculo).
- Vincular à pasta: exige `canAccessResource(..., 'pasta', id, 'edit')`; cadeado e lápide da pasta valem por cima.
- Tela pública: sem login; o token decide o escritório e o documento.

## 6. Migrations (número dado pelo Coordenador)

| Migration | Etapa | Conteúdo |
|---|---|---|
| M1 | 1 | tabelas documento, signatário e evento; permissão do módulo (INSERT idempotente e concessão) |
| M2 | 3 | código, arquivo final e vínculo com a pasta no documento |
| M3 | 4 | tabela de modelos do escritório |
| M4 | 5 | tabela de versões do PDF; colunas da análise e da cadeia (etapa 6 reaproveita) |
| M5 | 7 | colunas da selfie no signatário |

Cada uma sai sozinha, depois do merge da anterior ("uma migration por vez").

## 7. Decisões

Decisões do Coordenador em 09/11/2026, pela recomendação (registro em `bluejus-coordenacao/decisoes-09-11.md`):

- **D1. Biometria no nível Reforçada**: a primeira versão guarda selfie com documento e localização **sem comparação de
  face**. Fornecedor só depois, se for querido.
- **D2. Cadeia gov.br e ICP-Brasil no servidor (ESPEC)**: container interno com pyHanko (grátis). Lacuna Rest PKI fica
  descartada.
- **D3. Selo PAdES**: comprar o e-CNPJ A1 do BlueJus (R$ 165 a 280 por ano) fica com o Samuel, para a etapa 8, depois
  do piloto. Não trava nenhuma etapa anterior.
- **D4. Cabeçalho**: a tela interna usa a moldura do sistema (`base.html.twig`), como em `docs/specs/agenda-1a.md`; o
  conteúdo é o do desenho e o link entra no menu existente. Vale igual para Ponto e Voto. Domínio `AssinaturaEletronica`
  confirmado.

- **D5. Acréscimos ao desenho (era a P1)**, decididos pelas três regras do dono (registro em
  `bluejus-coordenacao/decisoes-desenhos-0911.md`):
  - aviso na procuração: **não entra** (regra 3: sem ele nada quebra nem fere lei);
  - confirmação antes de "Cancelar envio" (etapa 2): **entra**, porque invalida as assinaturas já colhidas;
  - confirmação antes de excluir modelo (etapa 4): **entra**, porque o modelo se perde de vez, sem Lixeira;
  - as duas confirmações usam o modal do próprio desenho, o mesmo do Excluir.

- **D6. Conferência ✓/✗ das etapas 1, 2, 4 e 7 contra o desenho** (bluejus-5f e Coordenador, 09/11/2026; lista em
  `bluejus-coordenacao/conferencia-assinatura-4d83f8b29.md`). O que ficou como o desenho: a Lixeira mantém Expirado o
  documento vencido (L364); escolher um modelo depois de retomar um rascunho começa outro documento (L370); o prazo vai
  como foi digitado e o servidor recusa fora de 1 a 90 com a regra escrita, sem a tela cortar em silêncio; "Salvar
  rascunho" volta à aba em que estava (L461); "Alterar logo" aceita qualquer imagem (L180, o canvas a converte em PNG);
  o título da tela pública e os textos da trilha (envio com o hash, "Assinado por {nome digitado} · E-mail e código",
  "Recusado por", "Documento concluído", "Envio cancelado. Documento e evidências preservados"); sem mãozinha nos
  rótulos da logo e da ordem; a seleção com 30px também no celular. Desvios registrados (⚠), cada um pelo defeito do
  desenho que corrige:
  - signatário vindo do modelo com o campo do cliente vazio: o nome vem com a linha "____________" do desenho (L372) e
    o e-mail fica vazio, porque o servidor só aceita e-mail válido, até no "Salvar rascunho" (no desenho, o e-mail
    "____________" travaria o rascunho); nome só de sublinhados conta como vazio no "Informe o nome de todos os
    signatários.";
  - aviso (toast) e janelas com `box-sizing: border-box` (lista, assistente e tela pública): no desenho (L281, L283,
    L285, content-box) o aviso passa 4px da largura da tela no celular e as janelas medem 2px a mais que o
    `max-width` declarado;
  - movimento reduzido também para a animação do aviso: no desenho (L110) só `.bjaBar` e `.bjaMov` param, e o aviso
    continua subindo para quem pediu menos movimento;
  - animação `bjaToastUp` no lugar de `bjaUp`: a do desenho termina em `transform: none` e tira o aviso do centro;
  - foco visível em campos (`.bjaF`: borda #0a6fa0 e contorno 2px #e5f1f7) e botões (contorno 2px #0a6fa0): o desenho
    não define foco, e sem ele quem usa o teclado não vê onde está.

## 8. Pendências do Samuel (ação, não decisão)

- Etapa 3: criar a variável do segredo do selo em produção; publicar a correção do `symfony/process` (PR separado
  `fix-symfony-process-producao`).
- Etapa 3 e 7: liberar a câmera no nginx de produção (`camera=(self)`) e publicar.
- Etapa 6: aprovar o container pyHanko no `docker-compose.prod.yml` e publicar.
- Etapa 8: decidir a compra do e-CNPJ A1 depois do piloto (D3).

## 9. Para o pacote jurídico (não trava)

- Texto do aceite do signatário e a menção à MP 2.200-2, art. 10, §2º.
- Base legal da biometria (art. 11, II, "g") e aviso ao cliente, antes de ligar a etapa 7.
- Prazo de guarda das evidências, da selfie e da foto do documento.
- Cláusula de suboperador (e-mail, biometria) no contrato BlueJus x escritório.
- Nível mínimo recomendado por tipo de documento (procuração).
