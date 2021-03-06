<?php

// note: sleep(1) are added to limit the api calls frequency (interval required of 1 second for safecex)

function doSafecexCancelOrder($orderID)
{
	if (empty($orderID)) return false;

	sleep(1);
	$res = safecex_api_user('cancelorder', "&id={$orderID}");
	if($res && $res->status == 'ok')
	{
		$db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
			':market'=>'safecex', ':uuid'=>$orderID
		));
		if($db_order) $db_order->delete();
		return true;
	}
	return false;
}

function doSafecexTrading($quick=false)
{
	$exchange = 'safecex';
	$updatebalances = true;

	if (exchange_get($exchange, 'disabled')) return;

	// {"symbol":"BTC","balance":0.01056525,"pending":0,"orders":0,"total":0.01056525,"deposit":"15pQYjcBJxo3RQfJe6C5pYxHcxAjzVyTfv","withdraw":"1E1..."}
	$balances = safecex_api_user('getbalances');
	if(empty($balances)) return;

	$savebalance = getdbosql('db_balances', "name='$exchange'");

	foreach($balances as $balance)
	{
		if ($balance->symbol == 'BTC') {
			if (is_object($savebalance)) {
				$savebalance->balance = $balance->balance;
				$savebalance->save();
			}
			continue;
		}
		if ($updatebalances) {
			// store available balance in market table
			$coins = getdbolist('db_coins', "symbol=:symbol OR symbol2=:symbol",
				array(':symbol'=>$balance->symbol)
			);
			if (empty($coins)) continue;
			foreach ($coins as $coin) {
				$market = getdbosql('db_markets', "coinid=:coinid AND name='$exchange'", array(':coinid'=>$coin->id));
				if (!$market) continue;
				$market->balance = $balance->balance;
				$market->ontrade = $balance->orders;
				if (!empty($balance->deposit) && $market->deposit_address != $balance->deposit) {
					debuglog("$exchange: {$coin->symbol} deposit address updated");
					$market->deposit_address = $balance->deposit;
				}
				$market->balancetime = time();
				$market->save();
			}
		}
	}

	if (!YAAMP_ALLOW_EXCHANGE) return;

	$flushall = rand(0, 8) == 0;
	if($quick) $flushall = false;

	// minimum order allowed by the exchange
	$min_btc_trade = exchange_get($exchange, 'trade_min_btc', 0.00010000);
	// sell on ask price + 5%
	$sell_ask_pct = exchange_get($exchange, 'trade_sell_ask_pct', 1.05);
	// cancel order if our price is more than ask price + 20%
	$cancel_ask_pct = exchange_get($exchange, 'trade_cancel_ask_pct', 1.20);

	sleep(1);
	$orders = safecex_api_user('getopenorders');

	if(!empty($orders))
	foreach($orders as $order)
	{
		// order: {"id":"1569917","market":"XXX\/BTC","type":"sell","time":"1457380288","price":"0.0000075","amount":"43.61658","remain":"43.61658"}
		$pairs = explode("/", $order->market);
		$symbol = $pairs[0];
		if ($pairs[1] != 'BTC') continue;

		$coin = getdbosql('db_coins', "symbol=:symbol OR symbol2=:symbol", array(':symbol'=>$symbol));
		if(!$coin || is_array($coin)) continue;
		if($coin->dontsell) continue;

		// ignore buy orders
		if($order->type != 'sell') continue;

		sleep(1);
		$ticker = safecex_api_query('getmarket', "?market={$order->market}");
		// {"name":"Coin","market":"XXX\/BTC","open":"0","last":"0.00000596","low":null,"high":null,"bid":"0.00000518","ask":"0.00000583","volume":null,"volumebtc":"0"}
		if(empty($ticker)) continue;

		$ask = bitcoinvaluetoa($ticker->ask);
		$sellprice = bitcoinvaluetoa($order->price);

		// flush orders over the 20% range of the current (lowest) ask
		if($sellprice > $ask*$cancel_ask_pct || $flushall)
		{
			debuglog("safecex: cancel order {$order->market} at $sellprice, ask price is now $ask");
			sleep(1);
			doSafecexCancelOrder($order->id);
		}

		// store existing orders in the db
		else
		{
			$db_order = getdbosql('db_orders', "market=:market AND uuid=:uuid", array(
				':market'=>$exchange, ':uuid'=>$order->id
			));
			if($db_order) continue;

			debuglog("safecex: store new order of {$order->amount} {$coin->symbol} at $sellprice BTC");

			$db_order = new db_orders;
			$db_order->market = $exchange;
			$db_order->coinid = $coin->id;
			$db_order->amount = $order->amount;
			$db_order->price = $sellprice;
			$db_order->ask = $ticker->ask;
			$db_order->bid = $ticker->bid;
			$db_order->uuid = $order->id;
			$db_order->created = $order->time;
			$db_order->save();
		}
	}

	// flush obsolete orders
	$list = getdbolist('db_orders', "market='$exchange'");
	if (!empty($list) && !empty($orders))
	foreach($list as $db_order)
	{
		$coin = getdbo('db_coins', $db_order->coinid);
		if(!$coin) continue;

		$found = false;
		foreach($orders as $order) {
			if($order->type != 'sell') continue;
			if($order->id == $db_order->uuid) {
				// debuglog("$exchange: order waiting, {$order->amount} {$coin->symbol}");
				$found = true;
				break;
			}
		}

		if(!$found) {
			debuglog("$exchange: delete db order {$db_order->amount} {$coin->symbol}");
			$db_order->delete();
		}
	}

	// add orders

	foreach($balances as $balance)
	{
		if($balance->symbol == 'BTC') continue;
		$amount = floatval($balance->balance);
		if(!$amount) continue;

		$coin = getdbosql('db_coins', "symbol=:symbol OR symbol2=:symbol", array(':symbol'=>$balance->symbol));
		if(!$coin || is_array($coin) || $coin->dontsell) continue;
		$symbol = $coin->symbol;
		if (!empty($coin->symbol2)) $symbol = $coin->symbol2;

		$market = getdbosql('db_markets', "coinid={$coin->id} AND name='$exchange'");
		if($market)
		{
			$market->lasttraded = time();
			$market->balance = bitcoinvaluetoa($balance->orders);
			$market->save();
		}

		if($amount*$coin->price < $min_btc_trade) continue;
		$pair = "{$balance->symbol}/BTC";

		sleep(1);
		$data = safecex_api_query('getorderbook', "?market=$pair");
		if(empty($data)) continue;
		// {"bids":[{"price":"0.00000517","amount":"20"},{"price":"0.00000457","amount":"1528.13069274"},..],"asks":[{...}]

		if($coin->sellonbid)
		for($i = 0; $i < 5 && $amount >= 0; $i++)
		{
			if(!isset($data->bids[$i])) break;

			$nextbuy = $data->bids[$i];
			if($amount*1.1 < $nextbuy->amount) break;

			$sellprice = bitcoinvaluetoa($nextbuy->price);
			$sellamount = min($amount, $nextbuy->amount);

			if($sellamount*$sellprice < $min_btc_trade) continue;

			debuglog("$exchange: selling $sellamount $symbol at $sellprice");
			sleep(1);
			$res = safecex_api_user('selllimit', "&market={$pair}&price={$sellprice}&amount={$sellamount}");
			if(!$res || $res->status != 'ok')
			{
				debuglog("selllimit bid: ".json_encode($res));
				break;
			}

			$amount -= $sellamount;
		}

		if($amount <= 0) continue;

		sleep(1);
		$ticker = safecex_api_query('getmarket', "?market=$pair");
		if(empty($ticker)) continue;

		if($coin->sellonbid)
			$sellprice = bitcoinvaluetoa($ticker->bid);
		else
			$sellprice = bitcoinvaluetoa($ticker->ask * $sell_ask_pct); // lowest ask price +5%
		if($amount*$sellprice < $min_btc_trade) continue;

		debuglog("$exchange: selling $amount $symbol at $sellprice");
		sleep(1);
		$res = safecex_api_user('selllimit', "&market={$pair}&price={$sellprice}&amount={$amount}");
		if(!$res || $res->status != 'ok')
		{
			debuglog("selllimit: ".json_encode($res));
			continue;
		}

		if (property_exists($res,'id')) {
			$db_order = new db_orders;
			$db_order->market = $exchange;
			$db_order->coinid = $coin->id;
			$db_order->amount = $amount;
			$db_order->price = $sellprice;
			$db_order->ask = $ticker->ask;
			$db_order->bid = $ticker->bid;
			$db_order->uuid = $res->id;
			$db_order->created = time();
			$db_order->save();
		}
	}

/* withdraw API doesn't exist
	$withdraw_min = exchange_get($exchange, 'withdraw_min_btc', EXCH_AUTO_WITHDRAW);
	$withdraw_fee = exchange_get($exchange, 'withdraw_fee_btc', 0.0002);
	$db_balance = getdbosql('db_balances', "name='$exchange'");
	if($withdraw_min > 0 && $db_balance->balance >= ($withdraw_min + $withdraw_fee))
	{
		$btcaddr = exchange_get($exchange, 'withdraw_btc_address', YAAMP_BTCADDRESS);
		$amount = $db_balance->balance - $withdraw_fee;
		debuglog("$exchange: withdraw $amount to $btcaddr");

		sleep(1);
		$res = safecex_api_user('withdraw', "&currency=BTC&amount={$amount}&address={$btcaddr}");
		debuglog("$exchange: withdraw: ".json_encode($res));

		if($res && $res->success)
		{
			$withdraw = new db_withdraws;
			$withdraw->market = $exchange;
			$withdraw->address = $btcaddr;
			$withdraw->amount = $amount;
			$withdraw->time = time();
			$withdraw->uuid = $res->id;
			$withdraw->save();

			$db_balance->balance = 0;
		}
	}
*/
}
