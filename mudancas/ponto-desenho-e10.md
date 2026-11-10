---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E10 do desenho 16 (VS 08.11.26): a batida gravada é só inserção (P65 a, P29, D18, P66). Migration `Version20261109280000`: tabela `ponto_ajuste`, gatilho `registro_ponto_so_insercao` (recusa qualquer UPDATE; DELETE só na purga do próprio escritório), coluna `sede.removida_em` e o CHECK da linhagem da inclusão. Todo setter da `RegistroPonto` recusa depois do id.
- Correção por linhagem em `ponto_ajuste` (inclusão, ajuste, desconsideração, restauração; vale a cabeça). Leitor único das batidas efetivas no `RegistroPontoRepository` (consultas, folha, banco de horas, PDF e XLSX), com teste de arquitetura.
- Ficha do gestor: "Incluir" (com motivo), "Ajustar", "Desconsiderar" e "Restaurar", em `AjusteDeBatidaController` com UseCases próprios; saem `pontoAdd`, `pontoEdit` e `pontoDelete` do `TenantController`. Na própria ficha, só o primário ou o Master faz as quatro (P43). Batida ligada a justificativa não se ajusta pela ficha.
- Marca "aj" na hora ajustada (folha, PDF, XLSX), com a dica "Ajustado por NOME em dd/mm/aaaa. Registro original preservado."
- Esquecimento abonado vira inclusão ligada à justificativa; voltar para análise a desconsidera; a justificativa que já mexeu na folha não se exclui. A purga apaga os ajustes com o escape do gatilho. O "desfazer" da auditoria não toca batida.
- Remover sede (na página "Sedes do ponto" e na aba Configurações): sem batida, apaga; com batida, encerra (`Ponto\Service\RemocaoDaSedeDoPonto`), e a sede some da lista, da cerca e do fuso do home office; as batidas mantêm a sede. Sai `RegistroPontoRepository::desvincularSede`.

## Para o cliente
- O registro de ponto gravado não muda mais. Quando o gestor corrige uma batida, o Ponto guarda a correção ao lado, e o registro original continua preservado.
- Na ficha do colaborador, o gestor passa a ter "Incluir", "Ajustar", "Desconsiderar" e "Restaurar" batida, sempre com o motivo da correção. "Editar" e "Excluir" saem.
- O gestor não corrige as próprias batidas: na ficha dele, só o usuário principal do escritório ou o Master corrige.
- A hora corrigida aparece com a marca "aj" na folha de ponto, no PDF e na planilha. Passando o mouse, aparece quem corrigiu e quando.
- A batida desconsiderada não conta nas horas, mas continua visível na linha "Desconsideradas" do dia, e pode ser restaurada.
- O esquecimento abonado continua entrando na folha, agora como uma correção ligada à justificativa. Se a justificativa voltar para análise, a batida sai da conta.
- A justificativa que já mexeu na folha não pode mais ser excluída pelo colaborador.
- Ao remover uma sede que já tem registros de ponto (pela página de sedes ou pela aba Configurações do Ponto), a sede sai da lista e da área permitida para bater o ponto, e os registros antigos continuam no histórico com o nome dela. A sede sem nenhum registro é apagada como antes.
