<?php

namespace App\Ponto\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistroPontoManualType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('data', DateType::class, [
                'label'       => 'Data',
                'widget'      => 'single_text',
                'mapped'      => false,
                'constraints' => [new NotBlank(message: 'Informe a data.')],
            ])
            ->add('hora', TimeType::class, [
                'label'        => 'Hora',
                'widget'       => 'single_text',
                'mapped'       => false,
                'with_seconds' => true,
                'constraints'  => [new NotBlank(message: 'Informe o horário.')],
            ])
            ->add('tipo', ChoiceType::class, [
                'label'   => 'Tipo',
                'choices' => [
                    'Entrada'  => 'entrada',
                    'Repouso'  => 'repouso',
                    'Retorno'  => 'retorno',
                    'Saída'    => 'saida',
                ],
                'constraints' => [new NotBlank(message: 'Selecione o tipo.')],
            ])
            // O motivo da correção é opcional (menos burocracia para o gestor); em branco, o serviço grava o texto padrão
            // (o CHECK de `ponto_ajuste.motivo` exige de 1 a 2000 caracteres).
            ->add('motivo', TextareaType::class, [
                'label'       => 'Motivo (opcional)',
                'required'    => false,
                'attr'        => ['rows' => 2, 'maxlength' => 2000, 'placeholder' => 'Por que a batida muda na folha'],
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'O motivo tem no máximo 2000 caracteres.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => null,
            // CSRF é validado explicitamente no controller (token por-intenção stateful
            // 'ponto_manual_add'/'ponto_ajustar'), NÃO pelo form: evita mecanismo duplo
            // com o token 'submit' stateless. Ver AjusteDeBatidaController::incluir/ajustar.
            'csrf_protection' => false,
        ]);
    }
}
