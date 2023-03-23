<?php
/* Copyright (C) 2023 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    mp/lib/mp.lib.php
 * \ingroup mp
 * \brief   Library files with common functions for MP
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function mpAdminPrepareHead()
{
	global $langs, $conf;
	$langs->load("mp@mp");
	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/mp/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/mp/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$head[$h][2] = 'myobject_extrafields';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/mp/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@mp:/mp/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@mp:/mp/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'mp@mp');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'mp@mp', 'remove');
	return $head;
}


/**
 * @var $obresponsemp
 * @return 1 ok | -1 nok
 */
function aprobarPagosEnElSistema($obresponsemp)
{

	global $user, $conf, $db;
	$error = 0;
	$errormessage = '';
	$datetimeobj = (new DateTime('now'))->format('Y-m-d\TH:i:s.vP');
	$datapago = json_decode($obresponsemp);


	if (!empty($obresponsemp)) {
		include_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
		$invoice = new Facture($db);
		$invoice->fetch($datapago->external_reference);
		include_once DOL_DOCUMENT_ROOT . '/custom/mp/lib/mp/autoload.php';
		MercadoPago\SDK::setAccessToken($conf->global->MP_ACCESS_TOKEN);
		/**
		 * VERIFICAMOS EL PAGO
		 * SI ESTA APROBADO ACTUALIZAMOS LA PREFERENCIA
		 */
		$payment = MercadoPago\Payment::get($datapago->payment_id);

		if ($payment->status == 'approved') {

			/**
			 * Actualizo el dia de expiracion de la preferencia.
			 * El enlace se encuentra activo, pero con preferencia expirada.
			 */
			$preference = MercadoPago\Preference::find_by_id($datapago->preference_id);
			$preference->expiration_date_to = $datetimeobj;
			$return_pref_update = $preference->update();
			/**
			 * SI SE ACTUALIZA LA PREFERENCIA
			 * REGISTRAMOS EL PAGO EN DOLIBARR
			 */
			if ($return_pref_update) {
				/**
				 * Registra el pago en dolibar
				 */
				if (empty($FinalPaymentMp)) {
					$FinalPaymentMp = $_SESSION["FinalPaymentMp"];
				}
				$now = dol_now();
				$paymentTypeId = 0;
				$paymentType = $conf->global->MP_TYPE_FOR_PAYMENTS;
				$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
				if (!empty($FinalPaymentMp) && $paymentTypeId > 0) {
					$db->begin();
					include_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
					$paiement = new Paiement($db);
					$paiement->datepaye = $now;
					if (!empty($conf->currency)) {
						$paiement->amounts = array($invoice->id => $FinalPaymentMp);
					} else {
						$paiement->multicurrency_amounts = array($invoice->id => $FinalPaymentMp);
						$errormessage = 'El pago se realizó en una moneda diferente a la moneda que se esperaba de la empresa';
						$error++; // Not yet supported
					}
					$paiement->paiementid   = $paymentTypeId;
					$paiement->num_payment = '';
					$paiement->note = 'Pago en línea' . dol_print_date($now, 'standard') . ' desde mercado pago con identificador de preferencia: ' . $datapago->preference_id;
					$paiement->note_public = 'Pago en línea' . dol_print_date($now, 'standard') . ' desde mercado pago con identificador de preferencia: ' . $datapago->preference_id;
					$paiement->ext_payment_id = $datapago->payment_id; //id de pago de mercado pago
					$paiement->ext_payment_site = 'MP';
					/**
					 * Verificar si la factura ya fue abonada
					 */
					$buscarFactura = 0;
					$sql = "SELECT COUNT(*) AS qty FROM llx_paiement_facture WHERE fk_facture =" . $invoice->id;
					$resql = $db->query($sql);
					if ($resql) {
						$obj = $db->fetch_object($resql);
						if ($obj) {
							$buscarFactura = $obj->qty;
						}
					};
					if ($buscarFactura) {
						$error++;
						$errormessage = "La factura con " . $invoice->ref . " ya fue registrada como pagada.";
					}
					/**
					 * Actualizamos el pago con referencia en nuestra base de datos mpapgos
					 */
					include_once DOL_DOCUMENT_ROOT . '/custom/mp/class/mppagos.class.php';
					$objectmp = new Mppagos($db);
					$data = array(
						/*'ref' => $invoice->id,
						'fk_facture' => $invoice->id,
						'fk_soc' => $invoice->socid,*/
						'mp_status' => $datapago->status,
						'mp_payment_id' => $datapago->payment_id,
						'mp_payment_type' => $datapago->payment_type,
						'mp_preference_id' => $datapago->preference_id,
						'mp_external_reference' => $datapago->external_reference,
						'mp_merchant_order_id' => $datapago->merchant_order_id,
						'mp_site_id' => $datapago->site_id,
						'description' => 'FACTURA ABONADA POR MERCADO PAGO',
						'status' => 1,
					);
					$mp_id = $objectmp->getupdateIdMp((int)$invoice->id, $data);
					if ($mp_id < 0) {
						$error++;
						$errormessage = "Error!! El registro no se actualizo..";
					}
					/**
					 * Si no esta abonada se registra el pago.
					 */
					if (!$error) {
						$paiement_id = $paiement->create($user, 1);
						if ($paiement_id < 0) {
							$errormessage = $paiement->error . ' ' . join("<br>\n", $paiement->errors);
							$error++;
						} else {
							$errormessage = 'Pago realizado con éxito..';
						}
					}
					if (!$error && !empty($conf->banque->enabled)) {
						$paymentmethod = $conf->global->MP_TYPE_FOR_PAYMENTS;
						if (!empty($conf->global->MP_BANK_ACCOUNT_FOR_PAYMENTS)) {
							$label = '(CustomerInvoicePayment)';
							if ($invoice->type == Facture::TYPE_CREDIT_NOTE) {
								$label = '(CustomerInvoicePaymentBack)'; // Refund of a credit note
							}
							$result = $paiement->addPaymentToBank($user, 'payment', $label, $conf->global->MP_BANK_ACCOUNT_FOR_PAYMENTS, '', '');
							if ($result < 0) {
								$errormessage = $paiement->error . ' ' . join("<br>\n", $paiement->errors);
								$error++;
							} else {
								$errormessage = 'La transaccion bancaria de pago, fue creada con exito..';
							}
						} else {
							$errormessage = 'Configuración de cuenta bancaria para usar en el módulo ' . $paymentmethod . ' no se configuró. Su pago fue realmente ejecutado pero no lo registramos. Por favor contáctenos.';
							$error++;
						}
					}
					if (!$error) {
						$db->commit();
					} else {
						$db->rollback();
					}
				} else {
					$errormessage = 'No se pudo obtener un valor válido para "cantidad pagada" (' . $FinalPaymentMp . ') o, la "identificación del tipo de pago" (' . $paymentTypeId . ') para registrar el pago de la factura. Puede ser que el pago ya se haya registrado.';
				};
			} else {
				$error++;
				$errormessage = "ERROR!!! La preferencia de pago no fue actualizada.";
			};
		} else {
			$error++;
			$errormessage = "ERROR!!! No se pudo registrar el número de pago.";
		};
	} else {
		$error++;
		$errormessage = "ERROR!!! datos de pago en mercado pago, no recibidos.";
	};


	if (!$error) {
		setEventMessages($errormessage, null, 'mesgs');
		return 1;
	} else {
		setEventMessages($errormessage, null, 'errors');
		return -1;
	}
}
