# Relatório da Central de envios: estrela, arquivo, remoção, menu e exportação (F3e)

Desenho: `docs/design/perfis-1.2/vs-21-10-26/05 - Perfis 1.2 - VS 21.10.26.dc.html`, marcação L300-305 (barra, abas,
linha) e L503-514 (visualizador e menu do botão direito), lógica L698-717. Continua a F3d
(`docs/specs/comercial-relatorio.md`, #209). Frente da 9b, `comercial-marcas`. Migration `Version20261020149000`
(reservada pelo coordenador). Risco BAIXO. Decisões do coordenador e da 9b em 21/10/2026.

## Estado da frente

- 21/10: spec; implementação começando.

## Divisão com a F3g (0d)

A F3g (rastreio de leitura por campanha, migration 149100) é dona do `MetricasDaCampanha`, do trecho de leitura do
`comercial-relatorio.js` (a linha ✓/✓✓, a dica, os 2 KPIs de leitura, o texto do visualizador) e da coluna
`comercial_campanha.rastrear_leitura`. Esta frente não toca nesses pontos. Onde precisa saber se a campanha rastreou
leitura (o CSV e o "Reenviar a quem não leu"), usa hoje "não rastreada" fixo (o rastreio está desligado) e troca pelo
`RastreioDaLeitura::efetivo()` quando a F3g entrar. Quem entrar por último traz o master.

## Onde guarda: marcas por Master

Tabela `comercial_campanha_marca`: `campanha_id` (FK `comercial_campanha`, ON DELETE CASCADE), `user_id` (FK
`"user"`, ON DELETE CASCADE), `estrela` bool, `arquivada` bool, `removida_em` timestamp nulo, `alterada_em`; PK
(`campanha_id`, `user_id`). Cada Master tem as próprias marcas (no desenho elas ficam no navegador de quem marcou).
Sem linha = sem marca. Tabela da plataforma, sem tenant, como o resto do Comercial. Entidade Doctrine para o
`doctrine:schema:validate` ficar em dia; o acesso segue o padrão do Comercial (SQL na conexão DBAL).

**Remover do relatório** só esconde, para quem removeu (`removida_em`): nada da campanha nem dos envios é apagado.
Não há desfazer na tela (o desenho não tem).

## Regras

1. **Abas** (dc L702): Enviadas, Agendadas, Recorrentes, **Com estrela** ("Campanhas marcadas", `#c08a12`, `bi-star`)
   e **Arquivadas** ("Fora das listas", `#5b7383`, `bi-archive`). Arquivada sai das três primeiras e da estrela e só
   aparece em Arquivadas. Com estrela = estrela e não arquivada. Removida não aparece em aba nenhuma nem conta.
   Contagens como o dc: cada uma pela própria regra, sem a busca.
2. **Linha:** botão da estrela (`bi-star` / `bi-star-fill`) antes do público, que alterna a estrela daquela campanha
   sem abrir o visualizador. `title` da linha: "Abrir · botão direito para mais opções".
3. **Seleção** (barra, dc L301): com algo selecionado aparecem "Arquivar" (na aba Arquivadas, "Desarquivar"),
   "Estrela" (marca todas com estrela; title "Marcar com estrela") e o botão da lixeira (title "Remover do
   relatório", pergunta "Remover N campanha(s) do relatório?"). Depois da ação a seleção limpa e a lista recarrega.
4. **Menu do botão direito** na linha (dc L704), título = nome da campanha, fecha no clique fora, no Esc e no
   botão direito fora; itens e separadores na ordem do dc:
   - Abrir campanha (`bi-envelope-open`);
   - Duplicar como nova (`bi-files`): o mesmo evento `mc:duplicar-campanha` da F3d; aviso "Campanha duplicada:
     ajuste e envie.";
   - Reenviar a quem não leu (`bi-arrow-repeat`): só aviso. Sem rastreio: "Esta campanha não rastreou leitura.";
     com rastreio: "Reenvio disponível quando o servidor registrar as leituras.";
   - separador; Encaminhar para um e-mail (`bi-forward`): `prompt` "Encaminhar esta campanha (como teste) para:";
     aviso "Encaminhado para {e-mail}.";
   - Marcar com estrela / Remover estrela (`bi-star` / `bi-star-fill`); Arquivar / Desarquivar (`bi-archive`);
   - separador; Exportar destinatários (CSV) (`bi-filetype-csv`);
   - separador; Excluir do relatório (`bi-trash3`, cor `#a3232b`): pergunta "Excluir esta campanha do relatório?"
     (o mesmo "remover" da regra 3).
5. **Visualizador** (dc L503): ganha "Reenviar a quem não leu" (aviso; sem rastreio: "Esta campanha não rastreou
   leitura; não dá para saber quem não leu."; com rastreio: "Reenvio para quem não leu fica disponível assim que o
   servidor registrar as leituras.") e "Exportar destinatários", na ordem do dc (Duplicar, Reenviar, Exportar,
   Cancelar envio).
6. **Exportar destinatários (CSV):** pelo servidor (todos os destinatários, não só os 200 da tela), UTF-8 com BOM,
   separador `;`, arquivo `destinatarios-{id}.csv`. Do visualizador: `E-mail;Enviado;Leitura` (dc L708), Enviado
   `sim` quando o envio saiu (`enviado`, `falhou`, `incerto`) e `não` nos demais; Leitura `não rastreada` (fixo até a
   F3g). Do menu: só a coluna `E-mail` (dc L704). **Sem e-mail anonimizado** (o hash da retenção de 24 meses, sem
   `@`, fica de fora). Célula que começa com `=`, `+`, `-`, `@`, tab ou CR leva `'` na frente (injeção de fórmula).
   Cada exportação grava no `audit_log` (`camp_csv`, entidade `CampanhaDeEmail`, `changes` = {linhas, formato}, o
   Master como autor), sem os e-mails.
7. **Encaminhar para um e-mail:** pelo servidor, a campanha remontada da cópia congelada (`descongelar`) vai **só**
   ao e-mail digitado, pelo Carteiro, como o "Enviar teste" (sem rastreio, origem própria `encaminhar_campanha`,
   lista `comercial_campanhas`, descadastrado não recebe). E-mail validado. Teto: 10 por hora por Master, contado no
   `audit_log` (`camp_enc`, com o destino em `changes`, que é dado do Master). Sem isenção do teto diário (o
   `EnviarEmailHandler` não muda). Recusas em 422 com o texto: e-mail inválido "Informe um e-mail válido.", teto
   "Limite de 10 encaminhamentos por hora atingido.", descadastrado "Este e-mail está descadastrado da lista de
   campanhas; nada foi enviado.", campanha sem cópia "Esta campanha não pode ser encaminhada."
8. **Rotas** (todas `ROLE_SUPER_ADMIN`; POST com CSRF `master_comercial` no header `X-CSRF-Token`):
   - `GET /master/comercial/relatorio?aba=est|arq` (as abas novas na rota da F3d);
   - `POST /master/comercial/relatorio/marcas` com JSON `{ids: [..], acao: estrela|sem_estrela|arquivar|desarquivar|remover}`
     (até 50 ids; id inexistente é ignorado; ação inválida 422);
   - `GET /master/comercial/campanhas/{id}/destinatarios.csv?formato=completo|emails` (404 se não existe);
   - `POST /master/comercial/campanhas/{id}/encaminhar` com JSON `{email}`.

## Fora

- "Reenviar a quem não leu" de verdade (depende do rastreio, F3g e Política).
- Desfazer a remoção.
- Limpeza do comentário solto em `RelatorioDeCampanhasRepository` (sobra da F3d, antes do `filtro()`): sai nesta
  frente.
