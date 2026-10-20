# Spec: o Convidado do Trabalho Externo diante de escritório fora do ar

## Estado da frente (atualizado em 20/10/2026)

- Branch `te-convidado-escritorio-fora-do-ar`, **empilhada sobre o #132** (`trabalho-externo-campanha`, ponta da
  pilha do Trabalho Externo): `PoliticaDaDemanda`, `LocalizadorDeVaga` e o `VagaRepository` só existem como estão
  na pilha. Merge depois do #132.
- Risco **MÉDIO, revisado como ALTO** (escopo de acesso de externo; toca a pré-condição do caso de uso que cria o
  `User` pelo convite e uma regra de conta que o Tenant reusa). Revisão da b5 em 20/10: não aprovado (B1), corrigido
  em fa2f1524 e **aprovado** na re-revisão. Ressalva: `OfertarNovamente` está na guarda das vagas sem teste
  funcional próprio (o mesmo ramo de Aprovar e Reabrir, testados). **Sem migration.**
- Origem: achado da 0d no #155 (barreira para escritório suspenso nas rotas `/tenant/{id}`). O Convidado não tem
  escritório na URL (`PREFIXO_SEM_ESCRITORIO`) e a barreira não o pega. Proposta aprovada pelo coordenador em
  20/10 (itens 1 a 5), com os itens 6 e 7 acrescentados por decisão dele (reversível).
- **Para o dono** (pacote do coordenador): (i) a Central continua agindo nas demandas do escritório fora do ar
  (recomendação: sim, é ela quem encerra); (ii) excluído em quarentena perde a leitura e o suspenso mantém
  (recomendação). Até a resposta, a leitura fica para os dois.

## 1. Antes

- Nenhuma ação do Convidado confere a situação do escritório dono da demanda (`DemandaExterna::getTenant()`).
  Com o escritório suspenso pelo Master ou excluído (em quarentena), o Convidado continua mandando mensagem,
  registrando andamento e checklist, enviando documento, apagando a própria mensagem e usando os botões de
  execução (enviar para validação, informar impedimento, pedir prorrogação).
- O aceite do convite também não olha o escritório.
- Vagas abertas de escritório fora do ar seguem na lista de todo externo e aceitam candidatura.
- Os avisos à equipe (`DestinatariosDosAvisosRepository::ativosDoEscritorio`) seguem saindo para o escritório
  fora do ar.
- O `EntradaDoConvidadoListener` manda direto ao Trabalho Externo quem só tem escritório fora do ar e tem um
  convite: a pessoa não passa pela seleção de escritório e não vê o aviso de conta suspensa.

## 2. Depois

**Fora do ar** = `Tenant::isActive() !== true` ou `excluidoEm` preenchido (suspenso pelo Master, ou excluído e em quarentena, que também
fica inativo). Regra num lugar só: `App\Tenant\Service\SituacaoDaConta::foraDoAr(Tenant)`. A "suspensa a pedido"
do #125 (conta ativa com pedido de exclusão pendente) entra nessa regra quando o #125 estiver no master.

1. **Demanda: o Convidado só lê.** O `LocalizadorDeDemanda` (porta única da demanda por código, inclusive
   `GuardaDeExecucao` e as marcações em lote) devolve o contexto de Convidado com a marca `donoForaDoAr` quando
   o escritório da demanda está fora do ar. A `PoliticaDaDemanda` trata a marca como a pré-visualização do
   Master (`Contexto::somenteLeitura()`): sem botões, sem conversar, sem andamento ou checklist, sem anexar e sem
   apagar mensagem. A política continua pura; quem consulta a situação é o localizador.
2. **O que continua**: ver a demanda, o chat, o histórico e baixar documento (pergunta (ii) com o dono);
   marcar como lida e as marcações pessoais (só do próprio usuário, não mexem na demanda).
3. **Aviso na tela**: no lugar dos botões, "Esta demanda está pausada. Fale com a Central." Sem nome nem
   situação do escritório (o #155 evita esse vazamento para quem é de fora). As recusas do servidor seguem com a
   mensagem de cada ação, que já é neutra.
4. **Convite** de demanda de escritório fora do ar: o link tem a **mesma resposta do convite vencido** ("Convite
   indisponível", sem formulário), nos três caminhos (abrir, criar a conta e aceitar logado), pela regra única
   `ConviteDisponivel` (convite aceitável e escritório no ar). Nenhum caminho grava o aceite nem cria a conta
   (revisão da b5, B1: o criar-conta gravava o aceite antes da recusa). Quem já tinha aceito e volta ao link também
   vê "indisponível" e segue lendo a demanda (a leitura está com o dono, (ii)).
5. **Vagas** (item 6): vaga de escritório fora do ar sai da lista e do detalhe do externo, do link público
   `/vagas-juridicas/{token}` (`abertasEntre`) e do e-mail da campanha (`abertas`), e recusa candidatura ("Vaga
   indisponível"). Quem já se candidatou continua abrindo a vaga por "Minhas candidaturas" (só leitura). Vaga da
   Central ou de pessoa (sem escritório) não muda. Um só critério em DQL (`VagaRepository::donoNoAr`).
   **Master** (decisão do coordenador em 20/10, até o dono responder): a pendente desse escritório não entra na
   fila de aprovação, e aprovar, reabrir ou ofertar de novo é recusado (403, "O escritório que divulgou esta vaga
   está fora do ar. A vaga não pode ser publicada agora."), o que também evita o aviso "Nova vaga na sua área".
6. **Avisos e entrada** (item 7): `ativosDoEscritorio` não devolve ninguém de escritório fora do ar; a entrada
   pelo formulário não desvia para o Trabalho Externo quem tem vínculo ativo com escritório fora do ar, para a
   pessoa passar pela seleção e ver o aviso de suspensão.

**Não muda**: a Central (Master e Equipe) segue vendo e agindo na demanda (pergunta (i)); o Solicitante já é
barrado pela sessão (`TenantContextValidatorListener::barreiraDaConta`); currículo e alerta de vagas do Convidado
(não têm relação com o escritório da demanda); o perfil Convidado continua sendo dado pelo convite
(`FabricaDeContexto`), para ele não perder vagas e currículo.

## 2.1 Revisão da b5 (20/10): registrado

- **Duas leituras de "fora do ar"**: a `SituacaoDaConta` e os filtros desta frente usam `isActive` falso OU
  `excluidoEm` preenchido. A sessão (`TenantContextValidatorListener::barreiraDaConta`), o
  `UserTenantRepository::findActiveByUser`/`contasForaDoArDoUsuario` e outros pontos olham só `isActive`. Vale a
  invariante "excluído implica inativo" (`ExcluirEscritorioUseCase` desliga os dois juntos; `ReativarContaUseCase`
  liga e limpa juntos); unificar tudo pela `SituacaoDaConta` fica para depois do #125 e do #155, que tocam esses
  arquivos.
- **#125 ("suspensa a pedido")**: quem entrar por último (este PR ou o #125) estende `SituacaoDaConta::foraDoAr`
  com o pedido pendente; nada obriga isso no código, por isso fica aqui e no PR.
- **Escrita em andamento**: a que começou antes da suspensão termina (janela de milissegundos entre localizar e
  gravar). Aceito.
- **Checagens de escrita**: `agePeloEscritorio`, `podePropor`, `podeGerirConvites` e os lotes seguem olhando só
  `previa`; o Convidado não chega a eles. Não há teste de arquitetura que obrigue `somenteLeitura()` em toda
  checagem de escrita; fica como melhoria.

## 3. Verificação

- Unit: `SituacaoDaConta` (ativo, suspenso, excluído); `PoliticaDaDemanda` com `donoForaDoAr` (sem botões, sem
  conversar, andamento, anexar, apagar; ver e baixar continuam).
- Functional: Convidado com permissões completas numa demanda em execução, escritório suspenso: mensagem,
  andamento, checklist, documento e as três ações dão recusa; o detalhe abre com `pausada` e `botoes` vazio; o
  download continua; marcar lida continua. Reativado, volta a agir. Excluído: o mesmo. A Central segue agindo.
- Functional: aceitar convite de demanda de escritório fora do ar é recusado.
- Functional: vaga de escritório suspenso some da lista do externo e recusa candidatura; quem já se candidatou
  abre a vaga.
- Functional/integração: `ativosDoEscritorio` vazio para escritório fora do ar; login de quem tem só escritório
  suspenso e um convite vai para a seleção de escritório.
