---
tipo: novidade
---
## Changelog
- Ponto Eletrônico, etapa E7 do desenho 16 (VS 08.11.26), "Liga e desliga": a tela `ponto` e a função `ponto-geo` entram no catálogo do Master (Telas e recursos), ligadas. Com a tela desligada, quem não é Master vê a página "em preparação" na /ponto e na batida; o estado do dia, o alerta de horário e o cartão de pendência continuam. A rota do Master aceita chave com hífen.
- Aba Configurações do Ponto: seção das funções, com a chave "Escritório" (quem administra o ponto do escritório, o usuário primário e o Master) e a "Global" (só o Master). O Global desligado trava a do escritório. Gravação por `ponto_configuracao_funcao`, com registro no histórico de alterações.
- Tabela nova `ponto_config_escritorio` (Version20261109180000): as chaves do escritório e os ajustes que as próximas etapas usam (raio da sede nova, limite e prazo do banco de horas, trabalho externo). Entra na purga do escritório.
- Geolocalização desligada: a batida entra sem exigir a localização e sem conferir a sede (não vira home office); a tela e o atalho do avatar não pedem a localização.

## Para o cliente
- O Master pode desligar a tela do Ponto Eletrônico para todos. Enquanto ela estiver desligada, quem abrir o Ponto vê o aviso de tela em preparação, e o atalho do ponto no seu nome mostra a situação do dia, sem o botão de registrar.
- Na tela do Ponto, a aba Configurações ganha a opção de geolocalização. O administrador do ponto do escritório decide se o registro precisa ser feito dentro do raio de uma sede.
- Com a geolocalização desligada no escritório, o ponto é registrado sem pedir a sua localização.
