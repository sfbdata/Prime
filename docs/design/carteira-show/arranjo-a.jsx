// ARRANJO A — "Lista em primeiro plano (abas)" para `cobranca_carteira_show`.
//
// Fonte reproduzível do wireframe: cole em `mcp__open-pencil__render` (parâmetro `jsx`, x=0, y=0).
// Renderizado e conferido em 10/08/2026; imagem exportada ao lado (`arranjo-a.png`).
//
// Ideia do arranjo: a lista de objetos cobrados é a razão de existir da página, então ela ocupa a
// largura inteira. Configuração e Documentos — hoje competindo com ela em peso e em espaço — saem
// para abas irmãs. Ganha: faixa de KPIs com VENCIDO (que a tela não mostra hoje), ordenação por
// saldo no cabeçalho da coluna, e paginação no rodapé do card.
//
// ATENÇÃO: é wireframe de ARRANJO, não especificação visual. Cores são aproximações do tema claro
// (accent #1F7A4D = --jp-accent do cobrancas.css). O alinhamento vertical dentro das células é
// simulado com padding uniforme porque o `counterAlign` não é suportado pelo renderer JSX do
// OpenPencil — na implementação real quem centraliza é `align-middle` do Bootstrap.

<Frame name="A — Lista em primeiro plano (abas)" w={1180} h="hug" flex="col" gap={0} p={0} bg="#F5F6F8" rounded={0}>
  <Frame name="Header" w="fill" h="hug" flex="col" gap={10} p={24} bg="#FFFFFF">
    <Frame name="TituloLinha" w="fill" h={38} flex="row" gap={12} p={0} bg="#FFFFFF" align="MIN">
      <Frame name="Voltar" w={34} h={34} flex="row" gap={0} p={9} bg="#EEF0F3" rounded={8}><Text size={14} color="#495057">&lt;</Text></Frame>
      <Frame name="TituloBox" w={280} h={34} flex="row" gap={0} p={2} bg="#FFFFFF"><Text size={26} weight="bold" color="#14181C">TOP LIFE II</Text></Frame>
      <Frame name="Espaco" w="fill" h={1} bg="#FFFFFF" />
      <Frame name="BtnImportar" w={186} h={38} flex="row" gap={8} p={11} bg="#1F7A4D" rounded={8}><Text size={14} weight="semibold" color="#FFFFFF">Importar relatorio</Text></Frame>
    </Frame>
    <Frame name="MetaLinha" w="fill" h={26} flex="row" gap={10} p={0} bg="#FFFFFF">
      <Frame name="ClienteBox" w={190} h={26} flex="row" gap={0} p={5} bg="#FFFFFF"><Text size={14} color="#6C757D">Condominio Top Life II</Text></Frame>
      <Frame name="ChipFrescor" w={310} h={26} flex="row" gap={6} p={6} bg="#F1F3F5" rounded={999}><Text size={12} color="#495057">Dados atualizados ate 10/08/2026 (hoje)</Text></Frame>
    </Frame>
  </Frame>

  <Frame name="KPIs" w="fill" h="hug" flex="row" gap={0} p={0} bg="#FFFFFF">
    <Frame name="KpiSaldo" w={320} h="hug" flex="col" gap={6} p={20} bg="#FFFFFF">
      <Text size={11} weight="semibold" color="#8A9199">SALDO CONSOLIDADO</Text>
      <Text size={30} weight="bold" color="#14181C">R$ 1.093.412,77</Text>
    </Frame>
    <Frame name="KpiVencido" w={300} h="hug" flex="col" gap={6} p={20} bg="#FFFFFF">
      <Text size={11} weight="semibold" color="#8A9199">VENCIDO</Text>
      <Text size={30} weight="bold" color="#C0392B">R$ 412.880,10</Text>
    </Frame>
    <Frame name="KpiObjetos" w={230} h="hug" flex="col" gap={6} p={20} bg="#FFFFFF">
      <Text size={11} weight="semibold" color="#8A9199">OBJETOS COBRADOS</Text>
      <Text size={30} weight="bold" color="#14181C">121</Text>
    </Frame>
    <Frame name="KpiAcordo" w={230} h="hug" flex="col" gap={6} p={20} bg="#FFFFFF">
      <Text size={11} weight="semibold" color="#8A9199">EM ACORDO</Text>
      <Text size={30} weight="bold" color="#14181C">37</Text>
    </Frame>
  </Frame>

  <Frame name="Abas" w="fill" h="hug" flex="row" gap={6} p={12} bg="#FFFFFF">
    <Frame name="AbaObjetos" w={200} h={38} flex="row" gap={6} p={11} bg="#E8F3ED" rounded={8}><Text size={14} weight="semibold" color="#1F7A4D">Objetos cobrados  121</Text></Frame>
    <Frame name="AbaDocs" w={150} h={38} flex="row" gap={6} p={11} bg="#FFFFFF" rounded={8}><Text size={14} color="#6C757D">Documentos  3</Text></Frame>
    <Frame name="AbaConfig" w={150} h={38} flex="row" gap={6} p={11} bg="#FFFFFF" rounded={8}><Text size={14} color="#6C757D">Configuracao</Text></Frame>
  </Frame>

  <Frame name="Corpo" w="fill" h="hug" flex="col" gap={16} p={20} bg="#F5F6F8">
    <Frame name="CardLista" w="fill" h="hug" flex="col" gap={0} p={0} bg="#FFFFFF" rounded={12}>
      <Frame name="Toolbar" w="fill" h={66} flex="row" gap={10} p={14} bg="#FFFFFF">
        <Frame name="Busca" w={400} h={38} flex="row" gap={8} p={11} bg="#F8F9FA" rounded={8}><Text size={13} color="#8A9199">Buscar por objeto ou pessoa cobrada...</Text></Frame>
        <Frame name="FiltroEstado" w={160} h={38} flex="row" gap={8} p={11} bg="#FFFFFF" rounded={8}><Text size={13} color="#495057">Estado: todos</Text></Frame>
        <Frame name="Espaco2" w="fill" h={1} bg="#FFFFFF" />
        <Frame name="ContagemBox" w={100} h={38} flex="row" gap={0} p={11} bg="#FFFFFF"><Text size={13} color="#6C757D">121 objetos</Text></Frame>
      </Frame>

      <Frame name="TabelaHead" w="fill" h={38} flex="row" gap={0} p={0} bg="#FAFBFC">
        <Frame name="ch1" w={380} h={38} flex="row" gap={0} p={13} bg="#FAFBFC"><Text size={11} weight="semibold" color="#8A9199">OBJETO</Text></Frame>
        <Frame name="ch2" w={320} h={38} flex="row" gap={0} p={13} bg="#FAFBFC"><Text size={11} weight="semibold" color="#8A9199">PESSOA COBRADA</Text></Frame>
        <Frame name="ch3" w={150} h={38} flex="row" gap={0} p={13} bg="#FAFBFC"><Text size={11} weight="semibold" color="#8A9199">ESTADO</Text></Frame>
        <Frame name="ch4" w={250} h={38} flex="row" gap={0} p={13} bg="#FAFBFC" justify="end"><Text size={11} weight="semibold" color="#1F7A4D">SALDO EXIGIVEL  v</Text></Frame>
      </Frame>

      <Frame name="Linha1" w="fill" h={50} flex="row" gap={0} p={0} bg="#FFFFFF">
        <Frame name="c11" w={380} h={50} flex="row" gap={0} p={15} bg="#FFFFFF"><Text size={14} weight="semibold" color="#14181C">UNIDADE 1204</Text></Frame>
        <Frame name="c12" w={320} h={50} flex="row" gap={0} p={15} bg="#FFFFFF"><Text size={14} color="#495057">Mariana Albuquerque</Text></Frame>
        <Frame name="c13" w={150} h={50} flex="row" gap={0} p={13} bg="#FFFFFF"><Frame name="Badge1" w={82} h={24} flex="row" gap={0} p={5} bg="#FDECEA" rounded={6}><Text size={12} weight="semibold" color="#C0392B">Vencido</Text></Frame></Frame>
        <Frame name="c14" w={250} h={50} flex="row" gap={0} p={15} bg="#FFFFFF" justify="end"><Text size={15} weight="bold" color="#14181C">R$ 18.402,55</Text></Frame>
      </Frame>

      <Frame name="Linha2" w="fill" h={50} flex="row" gap={0} p={0} bg="#FCFCFD">
        <Frame name="c21" w={380} h={50} flex="row" gap={0} p={15} bg="#FCFCFD"><Text size={14} weight="semibold" color="#14181C">UNIDADE 0803</Text></Frame>
        <Frame name="c22" w={320} h={50} flex="row" gap={0} p={15} bg="#FCFCFD"><Text size={14} color="#495057">Roberto Cavalcanti</Text></Frame>
        <Frame name="c23" w={150} h={50} flex="row" gap={0} p={13} bg="#FCFCFD"><Frame name="Badge2" w={96} h={24} flex="row" gap={0} p={5} bg="#E8F3ED" rounded={6}><Text size={12} weight="semibold" color="#1F7A4D">Em acordo</Text></Frame></Frame>
        <Frame name="c24" w={250} h={50} flex="row" gap={0} p={15} bg="#FCFCFD" justify="end"><Text size={15} weight="bold" color="#14181C">R$ 9.117,40</Text></Frame>
      </Frame>

      <Frame name="Linha3" w="fill" h={50} flex="row" gap={0} p={0} bg="#FFFFFF">
        <Frame name="c31" w={380} h={50} flex="row" gap={0} p={15} bg="#FFFFFF"><Text size={14} weight="semibold" color="#14181C">UNIDADE 0301</Text></Frame>
        <Frame name="c32" w={320} h={50} flex="row" gap={0} p={15} bg="#FFFFFF"><Text size={14} color="#495057">Helena Pecanha</Text></Frame>
        <Frame name="c33" w={150} h={50} flex="row" gap={0} p={13} bg="#FFFFFF"><Frame name="Badge3" w={72} h={24} flex="row" gap={0} p={5} bg="#F1F3F5" rounded={6}><Text size={12} weight="semibold" color="#6C757D">Em dia</Text></Frame></Frame>
        <Frame name="c34" w={250} h={50} flex="row" gap={0} p={15} bg="#FFFFFF" justify="end"><Text size={15} weight="bold" color="#14181C">R$ 3.240,00</Text></Frame>
      </Frame>

      <Frame name="Rodape" w="fill" h={52} flex="row" gap={10} p={15} bg="#FAFBFC">
        <Frame name="InfoBox" w={200} h={32} flex="row" gap={0} p={8} bg="#FAFBFC"><Text size={13} color="#6C757D">Mostrando 1-25 de 121</Text></Frame>
        <Frame name="e4" w="fill" h={1} bg="#FAFBFC" />
        <Frame name="PgPrev" w={34} h={32} flex="row" gap={0} p={8} bg="#FFFFFF" rounded={6}><Text size={13} color="#ADB5BD">&lt;</Text></Frame>
        <Frame name="PgInfo" w={44} h={32} flex="row" gap={0} p={8} bg="#FAFBFC"><Text size={13} weight="semibold" color="#495057">1 / 5</Text></Frame>
        <Frame name="PgNext" w={34} h={32} flex="row" gap={0} p={8} bg="#FFFFFF" rounded={6}><Text size={13} color="#495057">&gt;</Text></Frame>
      </Frame>
    </Frame>
  </Frame>
</Frame>
