# Cadastro do processo pela pasta (acompanhamento da aba Processo, desenho 02)

Sessão 0d, 21/10/2026. Origem: vistoria da 5d no desenho "02 - EXPEDIENTES - VS 18.10.26", itens fora do
#133. Plano e decisões aprovados pelo coordenador (bluejus-84 e o anterior). Sem migration.

## Estado da frente

| Item | O quê | Estado |
|---|---|---|
| a3 | Cadastro com validação no servidor e UseCase | feito (`CadastrarProcessoPelaPastaUseCase`) |
| a2 | Número que já existe no escritório | feito (vincula o existente e avisa) |
| a1 | Dados completos do CNJ no cadastro | feito (consulta guardada no servidor) |
| a4 | "Buscar no CNJ" para quem edita a pasta | feito (rota da pasta; re-revisão por ser permissão) |
| a6 | "Encaminhar via Chat I.A" no menu do cartão | feito (item no ⋮, `pasta-encaminhar.js`) |
| a5 | Movimentações digitadas visíveis na pasta | fora: tela que o desenho não tem, na fila de decisões do dono |

## a3: validação (desenho `procSalvarVinc`, L4538-4542)

- Número completo no padrão CNJ (20 dígitos), órgão julgador, classe e assunto obrigatórios, numa lista só,
  como o desenho: "Preencha: número do processo, órgão julgador, classe processual, assunto processual." (só os
  que faltam).
- O número existente é procurado antes da validação: o processo antigo com número fora do padrão continua
  sendo achado (a2).
- Situação e instância só nas opções da janela; vazio vira "Em andamento" e "G1".
- Datas válidas (AAAA-MM-DD); baixa não anterior à distribuição.
- Limites das colunas viram mensagem ("<Campo> passa de N caracteres."), não erro de banco.
- Parte sem nome e movimentação sem descrição são ignoradas, como no desenho.
- Recusa = 422 com a mensagem; nada gravado (nem processo, nem vínculo, nem registro).

## a2: número já cadastrado

Usa o processo existente, não aplica nada do que foi digitado e devolve o aviso "Este processo já estava
cadastrado no escritório. Os dados digitados não foram aplicados." (na janela, pelo aviso da pasta; sem
XHR, flash). Por id ou número novo, sem aviso.

## a1: dados do CNJ

O `_source` do "Buscar no CNJ" fica no servidor 30 minutos (`ConsultasDoCnjGuardadas`, cache da aplicação),
com a chave presa a escritório, pessoa, sessão de login e número. O navegador nunca manda esses dados
(seriam forjáveis); campos como `datajudRaw`, `nivelSigilo` ou `assuntos` no corpo são ignorados.

No cadastro, o `DatajudProcessoMapper` entra primeiro (assuntos, sigilo, formato, sistema, códigos, id do
Datajud, bruto) e a janela vence campo a campo, nos campos que ela tem (a data de baixa, que a janela não
tem, fica a do CNJ). Partes e movimentações são as da janela; a movimentação
que veio igual do CNJ guarda os códigos e os complementos do Datajud.

## a4: "Buscar no CNJ" dentro da pasta (permissão, risco MÉDIO)

**Hoje:** `POST /processos/api/search` exige o módulo `processos`; vincular exige EDIT na pasta. O editor
da pasta sem o módulo vê o botão e recebe 403.

**Regra nova:** dentro da pasta, a consulta vale para quem pode vincular processo nela.

- Rota nova `POST /pasta/{id}/processo/buscar-cnj` (`pasta_processo_buscar_cnj`), em `PastaController`:
  exige `canAccessResource(pasta, edit)` (a mesma do vínculo). Como rota de pasta, passa também pelo
  cadeado (`Visualizar` e `Oculto` recebem 403 no POST), pela lápide e pela aba oculta (mapeada para
  `processo`, como `pasta_vincular_processo`).
- Autorizada, encaminha (`forward`, sub-requisição no servidor) para o `datajudSearch` com o atributo
  `_cnj_pela_pasta` = id da pasta. O `datajudSearch` aceita o módulo `processos` **ou** esse atributo.
  O atributo só nasce no servidor: a rota `/processos/api/search` não tem parâmetro de caminho nem
  padrão que o preencha, e query ou corpo não viram atributo.
- Fora da pasta, nada muda: `/processos/api/search` continua exigindo o módulo.
- A janela da pasta passa a chamar a rota nova, com `X-Requested-With`: a recusa das barreiras volta em JSON e a
  janela mostra o motivo (`erro` ou `mensagem`).
- O guardar da consulta (a1) segue o mesmo, na sessão de quem consultou.
- Limite de uso: não há limitador hoje em nenhuma das duas rotas; continua igual (sem piora: a rota nova
  exige EDIT numa pasta do escritório).

**Testes:** editor sem o módulo consulta pela pasta (200); sem EDIT na pasta, 403; pasta com cadeado em
`Visualizar`, 403; pasta de outro escritório, 404; sem o módulo, `/processos/api/search` continua 403,
mesmo mandando `_cnj_pela_pasta` na query ou no corpo.

## a6: "Encaminhar via Chat I.A" (desenho L3057)

Item no menu (⋮) do cartão do processo, pelo Encaminhar da 5d (#126):
`BJCentral.despachar({pastaId, tipo: 'processo', itemId})` ou o evento `bj-central:despachar`
(§4.1 de `encaminhar-via-chat-ia.md`). O tipo `processo` já existe no servidor, que relê o processo e exige
que ele esteja vinculado à pasta. Posição do desenho: depois de "Compartilhar", antes de "Relatório geral de
processos", ícone `bi-send`. Como nos registros, só com a Central na página e fora da lápide.

## Achado fora do escopo

`DatajudProcessoMapper::fixOrgaoJulgador` estraga texto que já chega em UTF-8 correto ("1ª Vara Cível"
vira "1Âª VARA CÃ­VEL"). Antigo; registrado para o coordenador.
