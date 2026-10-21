---
tipo: alteracao
---
## Changelog
- Painel do Master, mapa "Onde estamos atuando": a seta de tendência por estado passa a ser o saldo de entradas menos saídas da equipe (`saida_da_equipe`), nos últimos 30 dias e nos 30 anteriores, nos modos "Geral" e "Só primários" (este conta só `era_primario`). Leitura nova `PainelMasterRepository::saidasRecentes`; o JSON segue `{ag, an}`, agora podendo ser negativo. Sem migration.
- Não conta como saída quem continua ativo em outro escritório; escritório em quarentena fica fora; a UF da saída é a da OAB e, sem ela (ou sem usuário), a do escritório; saída sem UF é ignorada; a mesma pessoa conta uma saída só.
- O título da seta deixa de dizer "só entradas" e mostra o saldo com sinal, como no desenho.
