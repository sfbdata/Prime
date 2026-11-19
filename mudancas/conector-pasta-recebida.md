---
tipo: alteracao
---
## Changelog
- Teste de integração novo (`ConectorNaPastaRecebidaTest`) que prova o isolamento da pasta recebida de outra conta no conector de I.A.: quem recebeu a pasta por convite (escritório B) não a alcança por nenhuma ferramenta (busca, leitura da pasta, processos, documentos, peças, financeiro, prazos e metas, andamento, registro, atualizar pasta, agenda e a confirmação de ação), e nada é gravado no escritório dono. Quem é do escritório dono segue usando a pasta normalmente pelo conector. Nenhuma mudança no código do conector foi necessária.
## Para o cliente
- Conferimos que a pasta recebida de outro escritório fica só na página própria dela. Pelo conector de I.A. (como o Claude), quem recebeu a pasta não consegue ler nem alterar nada dela nem do escritório que a enviou. O escritório dono continua usando a pasta pelo conector como sempre.
