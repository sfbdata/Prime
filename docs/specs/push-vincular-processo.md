# Push: modal "Vincular processo" (busca existente / cadastrar por CNJ), desenho 11

Fatia 1 da auditoria do desenho 11. Risco BAIXO (não mexe em identidade nem permissão; reaproveita UseCases do módulo Processo). Sem migration.

## Desenho
O botão "Cadastrar processo" (abas Publicações e Processos da OAB) abre o modal "Vincular processo" (largura 880):
topo azul com "Localizar pasta" e fechar; dois modos; rodapé Cancelar / Vincular processo.
- **Buscar processo existente**: campo "Número do processo, cliente ou pasta"; lista (até 8) com número e "Pasta N · tribunal";
  vazio: `Nenhum processo encontrado. Use "Cadastrar novo (CNJ)".` Vincular sem escolher: "Selecione um processo da lista.".
  Escolhido: "Processo N vinculado. As publicações passam a aparecer como Vinculadas."
- **Cadastrar novo (CNJ)**: abas Informações (número com máscara + "Buscar no CNJ", 14 campos), Partes (polo, nome, CPF/CNPJ,
  remover, "Adicionar parte"), Movimentações (vazio: "As movimentações aparecem depois de buscar no CNJ ou da primeira sincronização.").
  Erros: "Informe o número no formato CNJ antes de buscar.", "Informe o número do processo no formato CNJ.",
  "Preencha: órgão julgador, classe processual, assunto processual." Sucesso: "Processo N cadastrado e incluído no monitoramento."
- **Localizar pasta** (topo): fecha o modal e abre "Vincular a uma pasta" para o número digitado ou escolhido; sem número:
  "Informe ou escolha o número do processo antes de localizar a pasta."

## Decisões
1. Consulta ao CNJ: a rota existente `api_datajud_search` (POST /processos/api/search, já usada pela pasta; exige módulo `processos`)
   e a consulta guardada no servidor (`ConsultasDoCnjGuardadas`). Nada novo contra o DataJud, sem credencial nova.
2. Cadastrar: `CadastrarProcessoPelaPastaUseCase` (o mesmo da pasta; número já existente devolve o existente) com `CadastroDoProcessoInput`
   (inclui partes e movimentações). Os campos que o input não leva (valor da causa, jurisdição, segredo, 100% digital, justiça gratuita,
   tutela, prioridade) vão como dados manuais do processo (`Processo::definirDadosManuais`), que já existem para a pasta.
   Depois o monitoramento é retomado (entra em "Processos acompanhados") e a marca de "Excluir processo" é limpa.
3. Buscar existente: busca por número, cliente ou NUP entre os processos do escritório que o usuário pode ver (cadeado respeitado).
   Escolher = incluir o processo no acompanhamento do Push (retoma o monitoramento) e reconciliar as publicações com ele. O vínculo
   com pasta continua sendo o botão "Pasta" ou o "Localizar pasta".
4. Tribunal: derivado do número (somente leitura), como no desenho.

## Peças
- UseCases: `CadastrarProcessoNoPushUseCase`, `IncluirProcessoCadastradoNoPushUseCase`; `ProcessosDaOabRepository::buscarCadastrados`.
- Rotas em `/push-processual/processos`: `GET /cadastrados` (busca), `POST /cadastrar`, `POST /incluir-cadastrado`.
- Front: `_modal_vincular_processo.html.twig`, `push-vincular-processo.js`, regras `.pp-vp*` em `push-processual.css`.

## Testes
Functional das 3 rotas (cadastra e aparece em acompanhados; existente; campos obrigatórios; cadeado), e do markup do modal nas duas abas.
