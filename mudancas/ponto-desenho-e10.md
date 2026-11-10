---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E10 do desenho 16 (VS 08.11.26): a batida gravada é só inserção (P65 a, P29, D18, P66). Migration `Version20261109280000`: tabela `ponto_ajuste`, gatilho `registro_ponto_so_insercao` (recusa qualquer UPDATE; DELETE só na purga do próprio escritório), coluna `sede.removida_em` e o CHECK da linhagem da inclusão. Todo setter da `RegistroPonto` recusa depois do id.
- Correção por linhagem em `ponto_ajuste` (inclusão, ajuste, desconsideração, restauração; vale a cabeça). Leitor único das batidas efetivas no `RegistroPontoRepository` (consultas, folha, banco de horas, PDF e XLSX), com teste de arquitetura.
- Ficha do gestor: "Incluir" (com motivo), "Ajustar", "Desconsiderar" e "Restaurar", em `AjusteDeBatidaController` com UseCases próprios; saem `pontoAdd`, `pontoEdit` e `pontoDelete` do `TenantController`. Na própria ficha, só o primário ou o Master faz as quatro (P43). Batida ligada a justificativa em análise não se ajusta pela ficha (decisão C5).
- Decisão C5: com a justificativa ligada ainda em análise (o trabalho externo da E12; o esquecimento ligado a uma inclusão que conta), `AjustarBatidaUseCase`, o `DesconsiderarBatidaUseCase` pela tela e o `RestaurarBatidaUseCase` recusam com `em_analise` (`JustificativaPontoRepository::temEmAnaliseLigadaA`), e a página "Ajustar batida" nem abre; decidida, a ficha corrige como qualquer batida. O caminho da análise (`pelaAnalise`) não passa por essa recusa, e o Restaurar segue recusando a desconsideração dela.
- Ficha: a janela do "+" vira "Incluir batida" com o botão "Incluir", e a dica do "+" diz "Incluir saída" (o rótulo, não o código). Na própria ficha de quem não corrige (P43), a aba "Batidas de Ponto" diz por que os botões somem.
- Marca "aj" na hora ajustada na folha da tela, com a dica "Ajustado por NOME em dd/mm/aaaa. Registro original preservado.", e no XLSX, com a legenda nas observações. O PDF da folha (E16, em imagem) não leva o "aj": as observações do dia dizem "<Batida> ajustada em dd/mm/aaaa".
- Esquecimento abonado vira inclusão ligada à justificativa; voltar para análise a desconsidera; a justificativa que já mexeu na folha não se exclui. A purga apaga os ajustes com o escape do gatilho. O "desfazer" da auditoria não toca batida.
- Remover sede (na página "Sedes do ponto" e na aba Configurações): sem batida, apaga; com batida, encerra (`Ponto\Service\RemocaoDaSedeDoPonto`), e a sede some da lista, da cerca e do fuso do home office; as batidas mantêm a sede. Sai `RegistroPontoRepository::desvincularSede`.

## Para o cliente
- A correção de uma batida passa a ser guardada ao lado do registro de ponto original, que fica preservado como foi feito. A folha mostra a batida corrigida, e o original continua no histórico.
- Na ficha do colaborador, o gestor passa a ter "Incluir", "Ajustar", "Desconsiderar" e "Restaurar" batida, sempre com o motivo da correção. "Editar" e "Excluir" saem.
- O gestor não corrige as próprias batidas: na ficha dele, só o usuário principal do escritório ou o Master corrige. A aba "Batidas de Ponto" da própria ficha agora explica por que os botões de correção não aparecem.
- O botão "+" da folha agora diz o que faz ("Incluir saída", "Incluir entrada") e abre a janela "Incluir batida".
- A batida que tem uma justificativa ainda em análise (por exemplo, o trabalho externo) não pode ser ajustada, desconsiderada nem restaurada pela ficha, e a tela de ajuste nem abre: o gestor primeiro abona ou recusa a justificativa. Depois disso, a correção pela ficha volta a funcionar normalmente.
- A hora corrigida aparece com a marca "aj" na folha de ponto da tela e na planilha. Na tela, passando o mouse, aparece quem corrigiu e quando.
- Na folha em PDF, a correção aparece nas observações do dia: qual horário foi corrigido e em que data, por exemplo "Saída ajustada em 07/11/2026".
- A batida desconsiderada não conta nas horas, mas continua visível na linha "Desconsideradas" do dia, e pode ser restaurada.
- O esquecimento abonado continua entrando na folha, agora como uma correção ligada à justificativa. Se a justificativa voltar para análise, a batida sai da conta.
- A justificativa que já mexeu na folha não pode mais ser excluída pelo colaborador.
- Ao remover uma sede que já tem registros de ponto (pela página de sedes ou pela aba Configurações do Ponto), a sede sai da lista e da área permitida para bater o ponto, e os registros antigos continuam no histórico com o nome dela. A sede sem nenhum registro é apagada como antes.
