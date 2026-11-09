---
tipo: alteracao
---
## Changelog
- Ponto Eletrônico, etapa E6 do desenho 16 (VS 08.11.26): a aba "Configurações" da /ponto ganha as seções "Sedes e raio de registro" e "Feriados do escritório" no molde do desenho (parciais `_config_sedes`, `_config_sede_linha`, `_config_feriados`, `_config_feriados_lista`; comportamento em `ponto-configuracoes-sedes.js`). Cada seção só aparece com a permissão da página de hoje: sedes com `admin.ponto.manage`, feriados com `admin.tenant.settings.manage`. As páginas "Sedes do ponto" e "Feriados e Folgas" continuam.
- Rotas novas no domínio Ponto para gravar e remover sede e feriado (JSON, token da página, escritório da sessão, 403 sem a permissão da seção, 404 para o que é de outro escritório), com os UseCases `SalvarSedeDoPontoUseCase`, `RemoverSedeDoPontoUseCase`, `CadastrarFeriadoDoPontoUseCase` e `RemoverFeriadoDoPontoUseCase`. Remover pede confirmação na janela do desenho.
- Feriado por lotação (desvio necessário do P71, decidido pelo Coordenador): migration `Version20261109200000` acrescenta `feriado.lotacao_id` opcional (ON DELETE CASCADE). Os feriados de hoje ficam sem lotação e valem para todos, como antes. A regra mora em `FeriadosDaPessoa` e vale na folha, na ficha, no PDF, no XLSX, no saldo, nas horas pagas e nas pendências de registro. O comando dos feriados nacionais não conta o feriado de uma lotação só.
- Sede nova nasce com 150 m (P70 a), na aba e na página antiga (`Sede::RAIO_PADRAO`); as sedes que já existem ficam com o raio delas. As validações de latitude, longitude e raio ficam numa regra só (`RestricoesDaSede`), usada pela página antiga e pela aba; na aba, a coordenada aceita vírgula.

## Para o cliente
- No Ponto Eletrônico, a aba Configurações passa a mostrar as sedes do escritório e os feriados, para quem já cuidava dessas telas.
- Dá para cadastrar e ajustar sede ali mesmo: nome, latitude, longitude e raio. O botão "Usar minha posição" preenche a localização pelo aparelho. A página com o mapa e a busca por endereço continua, com um atalho na aba.
- A sede nova já vem com raio de 150 metros. As sedes que você já tem não mudam.
- O feriado pode valer para todo o escritório ou só para uma lotação: por exemplo, um feriado municipal só para quem trabalha naquela cidade. Os feriados que você já cadastrou continuam valendo para todos.
- Remover uma sede ou um feriado pede confirmação antes.
