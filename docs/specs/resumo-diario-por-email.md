# Spec: resumo diário por e-mail (item 146 do inventário de 21/10)

Risco: MÉDIO (e-mail para pessoas reais, leitura de dados de vários domínios). Desenho 07 (prefT 'resumo'): "Resumo
diário por e-mail · Prazos e metas do dia, às 8h", ligado por padrão. Hoje a chave gravava e nada a usava.
Migration `Version20261020148900` (nome dado pela coordenação).

## Decisões do coordenador (21/10/2026)

1. **De quem são os itens:** os prazos e as audiências abertos de hoje nas pastas de que a pessoa é a responsável (o
   responsável do prazo é texto livre; o da pasta é a pessoa); os compromissos de hoje do tipo Prazo ou Audiência na
   agenda dela (que criou ou de que participa), ainda agendados; as metas abertas (fora de revisão e concluída) que
   vencem hoje em que ela é responsável.
2. **Teto diário do Carteiro:** a origem `resumo_diario` fica fora do teto de 1.500 por dia: o resumo nunca é barrado
   por ele. Como as outras origens isentas, ele entra na contagem do dia (as campanhas do mesmo dia têm o que sobra).
3. **Dia sem nada:** não envia.
4. **Só em dia útil do escritório** (`DiaUtilDoEscritorio`: sábado, domingo e feriado passam).
5. **Cadeado:** pasta oculta para a pessoa (`AcessoAPasta::idsOcultosPara`) não aparece (nem o prazo, nem a meta, nem
   o compromisso ligado). E só para quem tem vínculo ativo no escritório e conta ativa; quem ainda não confirmou o
   e-mail também não recebe.

## Como funciona

- Comando `app:perfil:resumo-diario` (`--dry-run`, `--tenant=ID`), um escritório por vez, com trava de arquivo; cron
  às 8h de Brasília (`0 11 * * *` em UTC), em `DEPLOY.md` ("Resumo diário por e-mail").
- `EnviarResumoDiarioDoEscritorioUseCase`: membros ativos, menos quem desligou a chave (`PreferenciaDoUsuarioRepository
  ::idsQueDesligaram`, em lote; sem linha = ligada), `ColetorDoResumoDiario`, e o envio pelo Carteiro: tipo Lista,
  lista `resumo_diario` (o descadastro do rodapé também vale), escopo o escritório, assunto "Seus prazos e metas de
  hoje, dd/mm", corpo `templates/email/perfil/resumo_diario.html.twig`, botão "Abrir o BlueJus" (Expediente).
- Uma vez por pessoa, escritório e dia: `resumo_diario_envio` (UNIQUE tenant, usuário, dia), gravado por `INSERT ...
  ON CONFLICT DO NOTHING` ANTES do e-mail; quem não gravou não envia. Rodar de novo no mesmo dia não manda outra vez.
- Os prazos e os compromissos saem pela hora (os de dia inteiro primeiro).
- Falha de uma pessoa (e-mail inválido, Carteiro) só afeta ela: o registro do dia é desfeito, os outros seguem, e o
  comando sai com falha para o log do cron; a próxima rodada tenta de novo. Quem se descadastrou pelo rodapé conta à
  parte ("Descadastrados"), não como enviado.
- Pendência para o dono: quem se descadastra pelo link do rodapé continua vendo a chave ligada no Meu Perfil, e ligar
  ou desligar a chave não desfaz o descadastro (só o `reativar` do descadastro).
- Fora da auditoria (`NAO_AUDITAVEIS`: o envio já fica em `comercial_envio`); entra na purga do escritório.
