<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Campanha\SanitizadorDeHtmlDeEmail;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\DTO\ModeloSalvoOutput;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;

/**
 * "Salvar modelo" (dc L.551): o próprio ou, num modelo padrão do sistema, a versão do Master dele (pedido M5.8: uma linha
 * de `comercial_modelo` com `fixo_chave`, criada no primeiro salvar; o nome e o ícone seguem os do padrão). Grava o nome, o assunto, o selo, o título e o
 * conteúdo (o HTML sanitizado de novo). O modo não muda. Auditado (`modelo_upd`, só `{nome, modo}` e, no padrão, a `fixo_chave`).
 * F3i (§12): a imagem do modo simples tem de existir e não ter vencido; salva, vale por 1 ano a partir daqui.
 *
 * @phpstan-import-type Modelo from ModelosDeCampanha
 */
final class SalvarModeloDeCampanhaUseCase
{
    public const MENSAGEM = 'Modelo salvo.';

    public function __construct(
        private readonly ModeloDeCampanhaRepository $modelos,
        private readonly ModelosDeCampanha $fixos,
        private readonly SanitizadorDeHtmlDeEmail $sanitizador,
        private readonly ClockInterface $relogio,
        private readonly ImagensDaCampanha $imagens,
    ) {
    }

    public function executar(string $idOuChave, MensagemDaCampanha $m, User $por): ModeloSalvoOutput
    {
        $agora = $this->relogio->now();
        $padrao = $this->fixos->fixo($idOuChave);
        $modelo = $padrao === null ? $this->proprio($idOuChave) : $this->versaoDoMaster($idOuChave, $padrao, $por, $agora);
        if ($m->modo !== $modelo->getModo()) {
            throw new CampanhaRecusada('Modo do modelo inválido.');
        }
        $this->imagens->exigirVigente($m, $agora);
        $html = $m->modo === 'html' ? $this->sanitizador->sanitizar($m->html) : '';
        if ($padrao === null) {
            $modelo->renomear($m->nome);
        }
        $modelo->preencher($m->assunto, $m->selo, $m->titulo, ModeloDaGrade::conteudoParaGravar($m, $html), $agora);
        $this->modelos->salvar($modelo, ModeloDeCampanhaRepository::ALTERADO, $por);
        $this->imagens->renovarPeloModelo($m, $agora);
        $aviso = $m->modo === 'html' && SanitizadorDeHtmlDeEmail::temEstiloEmBloco($m->html) ? ' ' . SanitizadorDeHtmlDeEmail::AVISO_DE_ESTILO : '';
        $saida = $padrao === null ? ModeloDaGrade::deEntidade($modelo) : ModeloDaGrade::deEditado($modelo);

        return new ModeloSalvoOutput($saida, self::MENSAGEM . $aviso);
    }

    /**
     * A versão do Master de um modelo padrão: a que já existe ou uma nova, ainda sem gravar (nome e ícone do padrão).
     *
     * @param Modelo $padrao
     */
    private function versaoDoMaster(string $chave, array $padrao, User $por, \DateTimeImmutable $agora): ModeloDeCampanha
    {
        return $this->modelos->doFixo($chave) ?? ModeloDeCampanha::paraFixo($chave, $padrao['nome'], $padrao['icone'], $padrao['modo'], $por, $agora);
    }

    private function proprio(string $idOuChave): ModeloDeCampanha
    {
        $modelo = ctype_digit($idOuChave) ? $this->modelos->porId((int) $idOuChave) : null;

        return $modelo ?? throw new CampanhaRecusada('Modelo não encontrado.');
    }
}
