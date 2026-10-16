# 06. Plano incremental de implementação

> Auditoria integral da `pasta_show` (16/10/2026). Este plano **não está aprovado**: nada foi implementado. Cada entrega é pequena, de escopo fechado e independente das outras, salvo quando a coluna "Depende de" diz o contrário. Ordem recomendada no fim.
> Regras que valem para todas: teste antes (vermelho), implementação, testes direcionados pelo `scripts/frente-testar.sh fidelidade-designer <alvo>`, prova por mutação nos testes novos, revisão read-only (`/review`), commit local de código separado do de docs, sem push/merge/deploy. Sem travessão em texto visível. Arquivos globais (`base.html.twig`, `app.css`, `_filtro_barra`, `filtro-tabela.*`, `_avatar_usuario`, `_resp_chip`, `_resp_estilo`) só com decisão do dono. **Medir na tela** antes de declarar conforme qualquer item visual (o smoke é do dono).

## 0. Antes de qualquer entrega: decisões do dono

| # | Decisão | Destrava |
|---|---|---|
| D-0 | Autorizar o commit dos docs da E8d (pendentes desde 15/10) e desta auditoria, em dois commits de docs separados | Árvore limpa para começar |
| D-7 | A Lista 03 mudou online em 16/10 (266 linhas): reauditar a Lista antes de integrar a frente? | Integridade da E8d |
| D-6 | P1, P2, P3 definitivos | E6 (cabeçalho), itens VIS-21 a 24 |
| D-1 a D-5 | Políticas do `04-relatorio-divergencias.md` §3 (anotações, IA, lida, exclusão em Detalhes, número único) | P0-5, P1-7, P1-12 |
| D-8 | Autorizar a medição na tela (Playwright contra a 8000 e o protótipo online numa porta própria), recusada nesta sessão pelo classificador por poder interferir na demonstração | Todos os "PRECISA MEDIR"; responsividade (PZ-GLB-N06) |
| D-9 | Funcionalidades novas do desenho que exigem backend: Prazos e audiências, Réu, Suspenso/Cancelado, apensos, cadeado, link externo, e-mail ao cliente, IA com abas | Bloco P1 de backend (cada uma vira frente própria com spec) |
| **D-10** | **Conciliar com a E12**, que está em andamento na mesma worktree, sem commit (`E12-LISTA-INTEGRAL.md`). Ela já mexe em Suspenso/Cancelado, pasta trancada, administrador do escritório como "primário", destaque de pasta e marcadores, com a migration `Version20261016150000`. P1-9, parte da P1-11 e a área da P0-2 (marcadores) sobrepõem a E12: ordem e dono de cada arquivo antes de começar | Todo o plano; evita dois escritores nos mesmos arquivos |

## 1. P0: segurança e integridade

Risco MÉDIO (permissão): cada entrega registra spec curta em `docs/specs/` antes de implementar (regra do projeto) e passa por `feature-review-agent`.

| ID | Entrega | Escopo fechado | Arquivos | Testes | Critério de aceite | Depende de | Risco |
|---|---|---|---|---|---|---|---|
| **P0-3** | Erro 500 dos marcadores sem stack trace | Tirar `trace` do JSON; manter mensagem genérica; registrar a exceção no log | `app/src/Expediente/Controller/ExpedienteController.php:266-268` | Funcional: forçar exceção no UseCase (dublê) e verificar que o JSON não tem `trace` nem caminho de arquivo | JSON 500 só com `erro` genérico; log com a exceção | nenhuma | baixo |
| **P0-2** | Marcadores exigem EDITAR a pasta | `canAccessResource('pasta', id, edit)` no `expediente_pasta_marcadores`; no cabeçalho, "+" e clique nas etiquetas só com `podeEditarPasta` | `ExpedienteController.php:246-260`; `templates/pasta/_cabecalho.html.twig:102-111` | Funcional: perfil com módulo Expediente e só VER a pasta recebe 403 e a tela não traz o "+" nem o token; quem edita segue funcionando; a Lista do Expediente (mesmo endpoint) segue funcionando para quem edita | Recusa no servidor e na tela; nenhuma regressão na Lista | P0-3 (mesmo arquivo: fazer em sequência) | médio: a Lista usa o mesmo endpoint; conferir perfis reais antes (consulta só leitura) |
| **P0-1** | Metas exigem acesso à pasta | `view` em `tarefa_show`; `edit` em concluir, responsáveis, mensagem e editar mensagem; `metaEditavel` passa a exigir `podeEditarPasta` | `app/src/Controller/TarefaController.php` (legado: testes do comportamento atual primeiro, depois a mudança, sem mover o arquivo) `:225-258, 336, 400, 434-445`; `templates/pasta/_metas.html.twig:86-96` | Funcional: módulo Tarefas sem acesso à pasta (403 em cada rota); só VER (show 200, concluir 403); editar (tudo 200); Minhas Metas e o drawer da meta sem regressão; a aba devolvida por XHR (`ListaDeMetasDaPastaEmJson`) respeita a mesma regra | Mesma regra em concluir e reabrir; nenhuma rota de meta age sem acesso à pasta | nenhuma | médio: Minhas Metas e `tarefa/show` usam as mesmas rotas; medir impacto nos perfis |
| **P0-4** | Confirmar "Desvincular cliente" | `data-ps-cf="Desvincular cliente\nNOME sai desta pasta. …"` + `data-ps-cf-perigo` no form; texto avisa quando é o principal; erro por aviso da tela, não `alert()` | `templates/pasta/_cliente_linha.html.twig:89-96`; handler em `templates/pasta/show.html.twig:1483-1520` | Tela: o form tem `data-ps-cf` e perigo; `PastaConfirmacaoDoDesenhoJsTest` cobre; funcional existente de desvincular segue verde | Nenhum POST antes do "sim" (conferir no smoke) | nenhuma | baixo |
| **P0-5** | Modelos de checklist com permissão de escritório | Renomear e excluir modelo exigem permissão de administração/módulo (a definir) | `app/src/Pasta/Controller/PastaChecklistModeloController.php:72-77, 137-198`; `_documentos_checklist` (esconder ações) | Funcional: quem só edita a pasta não renomeia nem exclui modelo | Conforme a decisão | **D-1 a D-5** (decisão) | médio |

## 2. P1: funcionalidades essenciais ausentes ou incompletas

### 2.1 Sem backend novo (podem começar já)

| ID | Entrega | Escopo fechado | Arquivos | Testes | Critério de aceite | Depende de |
|---|---|---|---|---|---|---|
| **P1-1** | Drawer da IA acima da barra do topo (VIS-01, PZ-IA-01V) | Subir a camada do drawer e do véu acima da navbar (o Histórico usa 1056, a Timeline 1058) ou descontar a altura da navbar | `app/public/css/pasta-ia-agentes.css:144-166` | Folha: z-index do drawer acima de 1050; **medição na tela** obrigatória (cabeçalho e X visíveis, véu cobre a navbar) | Medido a 1440 e 390 | D-8 para medir |
| **P1-2** | Marcadores atualizam sem recarregar (PZ-MOD-11) | Passar `onSucessoCallback` ao modal na pasta e redesenhar as etiquetas do cabeçalho | `templates/pasta/show.html.twig` (inclusão do `_partials/modal_mover_marcador`), `_cabecalho.html.twig` | Tela: o show passa o callback; JS: função redesenha as etiquetas | Etiquetas mudam sem recarga | P0-2 (mesma área) |
| **P1-3** | Histórico registra vincular/desvincular processo e cadastro de cliente (PZ-MOD-20) | Evento de auditoria nesses 3 fluxos (tornar `PastaProcesso` Auditavel ou registrar evento no UseCase) | UseCases de vínculo de processo e de cliente; `AuditLog` | Unit dos UseCases; funcional do drawer mostrando a linha | Linha nova no Histórico | nenhuma (sem migration se usar o audit_log existente; conferir) |

### 2.2 Com backend novo (cada uma é frente própria, com spec, depois da decisão D-9)

Uma migration por vez (regra das frentes). Ordem sugerida pelo valor e pela dependência:

| ID | Entrega | Escopo | Dependências | Riscos | Critério de aceite |
|---|---|---|---|---|---|
| **P1-4** | Prazos e audiências: núcleo | Entidade de prazo da pasta (TenantAware, Auditavel, lápide), tipos prazo/audiência, criar, editar, cumprir (com Desfazer), excluir (janela vermelha); trilho com até 8 itens, contagem total, selos "Hoje/Faltam/Vencido" do desenho; janela com Esc e Enter; responsável escolhido entre colaboradores (não texto livre, defeito D20 do desenho) | Migration; rotas com `edit` da pasta e CSRF; `PastaPrazoOutput` passa a juntar metas e prazos manuais | Integridade de prazo jurídico; não copiar os defeitos D1, D19, D20, D22, D23 | Funcional de cada rota (inclusive outro escritório: 404), unit dos UseCases, trilho igual ao desenho medido na tela |
| **P1-5** | Prazos: agregação automática | Prazos do Push (`DeterminacoesDoJuizo` + data final) e de datas escritas nos registros, com motor de dias úteis no servidor (feriados nacionais e móveis, recesso; reaproveitar `Feriado`) e marca "estimado: conferir" | P1-4; decisão sobre o leitor de datas (o desenho faz no navegador) | Falso positivo de prazo (o desenho já marca "estimado") | Unit do motor com casos de feriado e recesso |
| **P1-6** | Prazos publicados para Lista, Agenda e agente | Endpoint de leitura dos prazos agregados por pasta (o desenho grava `bj-prazos-pasta-v1`) | P1-4; reauditoria da Lista 03 (D-7) | Desempenho na Lista | Funcional do endpoint com tenant |
| **P1-7** | Réu da pasta | Parte adversa por pasta (nome, CPF/CNPJ com dígito verificador, principal, ordem), aba Réu no Cadastro, coluna Réu da Lista | Migration; P1-8 (modal de Cadastro) ou janela própria; D-7 | Duplicidade com `ParteProcesso` (Datajud) | Funcional e unit; remover réu com janela vermelha (não `confirm()`, defeito D4) |
| **P1-8** | Cadastro e qualificação do cliente na pasta | Modal de 7 abas (Cliente, Réu, Endereço, Contato, Documentos, Qualificação, Histórico) com permissão do cliente; botão "Cadastro" abre ele, não o "Detalhes do cliente" | Rotas de edição do cliente reaproveitadas; decisão sobre preservar "Detalhes do cliente" [SÓ-BLUEJUS] | Escopo grande: fatiar por aba | Cada aba com teste de tela e funcional |
| **P1-9** | Situação Suspenso e Cancelado | Enum de situação, transições, selos, efeitos colaterais | Migration; regra de transição; listas e filtros que leem a situação | Telas que supõem só ativo/arquivado | Funcional das transições |
| **P1-10** | Apensos com tipo | Tipo de apenso em `PastaProcesso`, "Vincular apensado", selo com X, filtro por processo no Push | Migration | Telas que leem `principal` | Funcional e tela |
| **P1-11** | Cadeado, acesso por pasta e papel primário | ACL por pasta (oculto, ver, editar) com tela, selo, sobreposição "Pasta trancada" | `ResourceAccess`, `docs/AUTORIZACAO.md`; D-MASTER | **Risco ALTO** (autorização): spec, revisão e re-revisão | Testes de isolamento e de cada modo |
| **P1-12** | Regras de Detalhes (editar/excluir, papéis) | Conforme D-4 | P1-11 (papel primário) | Integridade do histórico | Unit e funcional |
| **P1-13** | Link externo do Push | Página pública com token, código, validade, revogação, registro de acessos | Serviço novo; segurança | **Risco alto** (exposição pública) | Testes de token expirado, revogado, outro escritório |
| **P1-14** | Push: enviar e avisar cliente | Cadastro de e-mails do cliente, envio com CC obrigatório e auditoria | E-mail transacional | Envio indevido | Funcional com mailer de teste |
| **P1-15** | Push: monitoramento e "Buscar agora" | Preferência por processo/apenso, captura sob demanda | Integração PJe | Fora do controle da frente | |
| **P1-16** | IA com abas e Êxito/Sucumbência | Abas do drawer; lançamento previsto no Financeiro | Backend de IA; Financeiro | | |

## 3. P2: fidelidade visual e interações secundárias

Só tela, sem backend, cada uma independente. Cada uma: teste de folha ou de tela antes, medição na tela depois (D-8), placar ✓/✗ contra o desenho.

| ID | Entrega | Itens da matriz | Arquivos prováveis | Critério de aceite |
|---|---|---|---|---|
| **P2-1** | Sem janela nativa: Excluir pasta com campo de número dentro da janela vermelha; erros por aviso da tela no lugar de `alert()` | PZ-CAB-25, PZ-MOD-26, PZ-CAB-03, 07, 24, 48, PZ-FIN-01, 02, 36, MB-CF-06 | `_cabecalho.html.twig:285-295`, `pasta-dialogos.js`, scripts do cabeçalho e do Financeiro, `nota-tecnica.js:360,373`, `pasta-explorador.js:2120`, `_detalhes_obs:170,184` | Nenhum `prompt()`/`alert()`/`confirm()` na pasta (teste que varre os JS e Twig da página); o servidor continua conferindo `confirmar_nup` |
| **P2-2** | Travessão em texto visível | PZ-GLB-VT, VIS-12 | `_historico_drawer.html.twig:74`, `_cabecalho.html.twig:311` | Teste que recusa `[—–]` nesses textos |
| **P2-3** | Próximos prazos no texto e na contagem do desenho (antes da P1-4) | PZ-TRI-03, N03, 04 (limite), VIS-08 | `PastaPrazoOutput.php:42` (limite e total), `_dados_trilho.html.twig` | "Hoje", "Faltam N dias", até 8, contagem total. O vazio do desenho espera a P1-4 (o texto dele promete o "+") |
| **P2-4** | Documentos com um cartão só; soltar arquivo no visor | PZ-DOC-00 (VIS-02), PZ-VIS-06 | `pasta-show.css:1274-1280`, `pasta-explorador-visor.js` | Medido na tela: sem moldura dupla |
| **P2-5** | Editar dados: foco no número, véu e posição do desenho | PZ-MOD-15, VIS-06 | `show.html.twig` (modal), `pasta-show.css` | Foco e seleção ao abrir; véu rgba(14,37,51,.38), topo 4vh medidos |
| **P2-6** | Movimento do desenho | VIS-09, VIS-10, PZ-GLB-12, PZ-CAB-35, PZ-CAB-45, PZ-MET-25 | `pasta-show.css`, `pasta-show.js` | Animações com `prefers-reduced-motion` respeitado |
| **P2-7** | Metas sem recarregar e link direto com aviso | PZ-MET-06, 08, N02, PZ-TRI-07 | `pasta-metas.js`, `_metas.html.twig` | Aviso da tela no lugar da recarga |
| **P2-8** | Miúdos de texto e tema | PZ-GLB-N04 (VIS-17), PZ-GLB-N05 (VIS-16), PZ-PUSH-25, PZ-CAB-15, PZ-TRI-12, PZ-DOC-13 | arquivos de cada item | Texto igual ao desenho; lista de menção com par escuro |
| **P2-9** | Responsividade | PZ-GLB-N06, VIS-04, VIS-15 | `pasta-show.css` | Só depois de medir (D-8): sem rolagem lateral a 390px; abas a 1024px sem rolagem escondida |

Ficam **fora** deste plano, registrados na matriz como Ausente com dependência: tudo que depende do Chat I.A./Central (Encaminhar, presença, comandos "/", destino das menções), de PJe/MNI, de OCR, de IA estruturada, de presença em tempo real, de busca global Ctrl+K (arquivo global) e de dicas `bj-dica` (arquivo global). Defeitos do próprio desenho (`04`, §8) **não** viram entrega.

## 4. Ordem recomendada

1. D-0 (commits de docs) e D-8 (medição na tela).
2. **P0-3 → P0-2** (mesmo arquivo, em sequência), **P0-1**, **P0-4**. Independentes entre si fora o par P0-3/P0-2; uma por vez, cada uma com revisão.
3. P1-1, P1-2, P1-3 (sem backend novo).
4. P2-1, P2-2 (regras do dono, sem decisão), depois P2-3 a P2-8; P2-9 depois da medição.
5. Bloco P1 de backend, uma frente por vez, depois da D-9, começando por P1-4 (prazos), que é o que o desenho de 16/10 mais trouxe.
6. Antes de integrar a frente: trazer o `origin/master`, suíte completa sozinha, e decidir D-7 (Lista 03).
