---
tipo: correcao
---
## Changelog
- Auditoria: o desfazer passa a seguir as regras de estado da tela, pelo novo `App\Auditoria\Service\EstadoDaTelaNoDesfazer`. Recusa, com aviso e sem mudar nada: o registro que mudou depois do log; a pasta excluída (lápide) e as filhas, a meta e a mensagem dela; a reabertura da meta que seguiu adiante; a reconclusão com data impossível; a etapa de meta concluída; a meta que ficaria concluída sem data ou aberta com data; o nome do cliente montado pela carteira; a situação e a situação do contrato da pasta. Na meta, roda com a meta travada numa transação. As regras foram extraídas das telas (`ReabrirMetaUseCase::seguiuAdiante`, `DesfazerReaberturaDaMetaUseCase::motivoParaRecusarAData`, `MontarEtapaDaMetaUseCase::podeMexerNaEtapa`, `RenomearClienteDaPastaUseCase::nomeEMontadoPelaCarteira`, `PastaSomenteLeituraListener::MENSAGEM`), que passam a chamá-las também. Sem migration.
- Auditoria: desfazer as etapas saltadas de uma meta dava erro 500; os valores voltam como etapas.

## Para o cliente
- Desfazer uma alteração no Registro de auditoria agora respeita as mesmas regras da tela: não reabre meta que já passou de etapa, não mexe em pasta excluída e não apaga uma alteração feita depois. Quando não puder, aparece o aviso e nada é alterado.
