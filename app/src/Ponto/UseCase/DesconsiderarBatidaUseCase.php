<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\DesconsiderarBatidaInput;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\GravacaoDoAjusteDoPonto;
use App\Ponto\Service\TrilhaDoPonto;

/**
 * Tira uma batida da conta da folha (E10, plano-e10-ponto §6). O registro original fica guardado e a batida aparece na
 * linha "Desconsideradas" da ficha, de onde a restauração a traz de volta. O evento `batida_desconsiderada` vai para a
 * trilha do Ponto no mesmo COMMIT.
 *
 * Dois caminhos ({@see DesconsiderarBatidaInput}): o gestor pela ficha confere a cabeça que a folha mostrou; a análise
 * da justificativa (E12) não confere a cabeça e liga a desconsideração à justificativa. Para a análise, "batida não
 * encontrada" quer dizer que não há nada a fazer. Roda na transação de quem chama, se houver (a da análise). A própria
 * batida só o primário ou o Master desconsidera (P43). Pela tela, a batida com justificativa ainda em análise não se
 * desconsidera: quem decide é a análise; decidida, desconsidera-se como qualquer outra (decisão C5). Recusado, nada é
 * gravado.
 */
final class DesconsiderarBatidaUseCase
{
    public function __construct(
        private readonly TrilhaDoPonto $trilha,
        private readonly GravacaoDoAjusteDoPonto $gravacao,
        private readonly RegistroPontoRepository $registros,
        private readonly AnaliseDaPropriaJustificativa $analiseDaPropria,
        private readonly JustificativaPontoRepository $justificativas,
    ) {
    }

    /**
     * @throws AjusteDeBatidaRecusadoException a própria batida (P43), batida fora desta folha, outro ajuste no meio
     *                                         ou justificativa em análise (só pela tela), batida já fora da conta ou
     *                                         motivo vazio
     */
    public function executar(DesconsiderarBatidaInput $input): PontoAjuste
    {
        $this->exigirQuePodeCorrigir($input);

        return $this->trilha->emTransacao(fn (): PontoAjuste => $this->desconsiderarSobATrava($input));
    }

    private function exigirQuePodeCorrigir(DesconsiderarBatidaInput $input): void
    {
        if (!$this->analiseDaPropria->permite($input->autor, $input->pessoa, $input->escritorio)) {
            throw AjusteDeBatidaRecusadoException::daPropriaBatida();
        }
    }

    private function desconsiderarSobATrava(DesconsiderarBatidaInput $input): PontoAjuste
    {
        $this->gravacao->travar($input->pessoa);
        $alvo = $this->alvoDaFolha($input);

        return $this->gravacao->desconsiderar($alvo, $input->motivo, $input->autor, $input->justificativa, null);
    }

    /** O alvo lido sob a trava; pela tela, com a cabeça que a folha mostrou. */
    private function alvoDaFolha(DesconsiderarBatidaInput $input): AlvoDoAjuste
    {
        $alvo = $this->registros->alvoDoAjuste($input->pessoa, $input->escritorio, $input->alvo)
            ?? throw AjusteDeBatidaRecusadoException::naoEncontrada();
        if ($input->conferirCabeca && $alvo->cabecaId() !== $input->cabecaEsperada) {
            throw AjusteDeBatidaRecusadoException::mudou();
        }
        // Pela tela (sem justificativa): a análise, que decide a justificativa, desconsidera mesmo em análise.
        if ($input->justificativa === null && $this->justificativas->temEmAnaliseLigadaA($alvo)) {
            throw AjusteDeBatidaRecusadoException::emAnalise();
        }

        return $alvo;
    }
}
