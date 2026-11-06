-- Minimal runner: test(name, fn) per case, done() exits non zero on any failure.
local failures, count = 0, 0

function test(name, fn)
	count = count + 1
	local ok, err = pcall(fn)
	if not ok then
		failures = failures + 1
		print("FAIL " .. name .. ": " .. tostring(err))
	end
end

function done()
	print(string.format("%d tests, %d failures", count, failures))
	os.exit(failures == 0)
end

-- Directory of the calling test file, to load the code under test relative to it.
function here()
	return debug.getinfo(2, "S").source:match("^@(.-)[^/]*$")
end
